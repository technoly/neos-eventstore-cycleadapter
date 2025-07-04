<?php

declare(strict_types=1);

namespace Technoly\NeosEventStore\CycleAdapter;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Exception\DBALException;
use Cycle\Database\Exception\StatementException;
use Cycle\Database\Exception\StatementException\ConstrainException;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Schema\AbstractTable;
use Cycle\Database\Table;
use DateTimeImmutable;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Exception\ConcurrencyException;
use Neos\EventStore\Helper\BatchEventStream;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\Events;
use Neos\EventStore\Model\EventStore\CommitResult;
use Neos\EventStore\Model\EventStore\Status;
use Neos\EventStore\Model\EventStream\EventStreamFilter;
use Neos\EventStore\Model\EventStream\EventStreamInterface;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use Neos\EventStore\Model\EventStream\MaybeVersion;
use Neos\EventStore\Model\EventStream\VirtualStreamName;
use Neos\EventStore\Model\EventStream\VirtualStreamType;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

final class CycleEventStore implements EventStoreInterface
{
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly DatabaseInterface $connection,
        private readonly string $eventTableName,
        private readonly LoggerInterface $logger,
        ClockInterface $clock = null
    ) {
        $this->clock = $clock ?? new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable();
            }
        };
    }

    public function load(
        VirtualStreamName|StreamName $streamName,
        EventStreamFilter $filter = null
    ): EventStreamInterface {
        $this->reconnectDatabaseConnection();
        $selectQuery = $this->connection
            ->select('*')
            ->from($this->eventTableName);

        $selectQuery = match ($streamName::class) {
            StreamName::class => $selectQuery->where('stream', $streamName->value),
            VirtualStreamName::class => match ($streamName->type) {
                VirtualStreamType::ALL => $selectQuery,
                VirtualStreamType::CATEGORY => $selectQuery->where('stream', 'LIKE', $streamName->value . '%'),
                VirtualStreamType::CORRELATION_ID => $selectQuery->where('correlationId', 'LIKE', $streamName->value),
            },
        };
        if ($filter !== null && $filter->eventTypes !== null) {
            $selectQuery->andWhere('type', 'IN', new Parameter($filter->eventTypes->toStringArray()));
        }
        return BatchEventStream::create(CycleEventStream::create($selectQuery), 100);
    }

    public function commit(StreamName $streamName, Event|Events $events, ExpectedVersion $expectedVersion): CommitResult
    {
        if ($events instanceof Event) {
            $events = Events::fromArray([$events]);
        }
        # Exponential backoff: initial interval = 5ms and 8 retry attempts = max 1275ms (= 1,275 seconds)
        # @see http://backoffcalculator.com/?attempts=8&rate=2&interval=5
        $retryWaitInterval = 0.005;
        $maxRetryAttempts = 8;
        $retryAttempt = 0;
        while (true) {
            $this->reconnectDatabaseConnection();
            if ($this->connection->getDriver()->getTransactionLevel() > 0) {
                throw new \RuntimeException('A transaction is active already, can\'t commit events!', 1547829131);
            }
            $this->connection->begin();
            try {
                $maybeVersion = $this->getStreamVersion($streamName);
                $expectedVersion->verifyVersion($maybeVersion);
                $version = $maybeVersion->isNothing() ? Version::first() : $maybeVersion->unwrap()->next();
                $lastCommittedVersion = $version;
                foreach ($events as $event) {
                    $this->commitEvent($streamName, $event, $version);
                    $lastCommittedVersion = $version;
                    $version = $version->next();
                }
                $lastInsertId = $this->connection->getDriver()->lastInsertID();
                if (!is_numeric($lastInsertId)) {
                    throw new \RuntimeException(
                        sprintf(
                            'Expected last insert id to be numeric, but it is: %s',
                            get_debug_type($lastInsertId)
                        ),
                        1651749706
                    );
                }
                $this->connection->commit();
                return new CommitResult($lastCommittedVersion, SequenceNumber::fromInteger((int)$lastInsertId));
            } catch (ConstrainException $exception) {
                if ($retryAttempt >= $maxRetryAttempts) {
                    $this->connection->rollBack();
                    throw new ConcurrencyException(
                        sprintf('Failed after %d retry attempts', $retryAttempt),
                        1573817175,
                        $exception
                    );
                }
                usleep((int)($retryWaitInterval * 1E6));
                $retryAttempt++;
                $retryWaitInterval *= 2;
                $this->connection->rollBack();
                continue;
            } catch (StatementException $exception) {
                // Catch "deadlock" and "lock wait timeout"
                if (in_array($exception->getCode(), ['40001', 'HY000'])) {
                    $this->connection->rollback();
                    throw new ConcurrencyException($exception->getMessage(), 1705330559, $exception);
                }
                throw $exception;
            } catch (DBALException|ConcurrencyException|\JsonException $exception) {
                $this->connection->rollback();
                throw $exception;
            } catch (\Throwable $exception) {
                $this->connection->rollback();
                $this->logger->error(
                    'Cycle commit events error {className}: {message} ({code}) with trace {stacktrace}',
                    [
                        'className' => get_class($exception),
                        'message' => $exception->getMessage(),
                        'code' => $exception->getCode(),
                        'stacktrace' => $exception->getTraceAsString(),
                    ]
                );
                throw $exception;
            }
        }
    }

    public function deleteStream(StreamName $streamName): void
    {
        $this->connection->delete($this->eventTableName, [
            'stream' => $streamName->value
        ])->run();
    }

    public function status(): Status
    {
        try {
            $this->connection->getDriver()->connect();
        } catch (\RuntimeException $e) {
            return Status::error(sprintf('Failed to connect to database: %s', $e->getMessage()));
        }
        // We do not check for schema changes, as the JSON column type in MariaDB always leads to a false positive
        // result in Cycle ORM
        return Status::ok();
    }

    protected function getSchema(): AbstractTable
    {
        Assert::notEmpty($this->eventTableName);
        $table = $this->connection->table($this->eventTableName);
        Assert::isInstanceOf($table, Table::class);
        $schema = $table->getSchema();
        // The monotonic sequence number
        $schema->column('sequencenumber')->primary();
        // The stream name, usually in the format "<BoundedContext>:<StreamName>"
        $schema->column('stream')->string()->nullable(false);
        // Version of the event in the respective stream
        $schema->column('version')->bigInteger()->nullable(false);
        // The event type in the format "<BoundedContext>:<EventType>"
        $schema->column('type')->string()->nullable(false);
        // The event payload as JSON
        $schema->column('payload')->text()->nullable(false);
        // The event metadata as JSON
        $schema->column('metadata')->json();
        // The unique event id, usually a UUID
        $schema->column('id')->string()->nullable(false);
        // An optional correlation id, usually a UUID
        $schema->column('correlationid')->string();
        // An optional causation id, usually a UUID
        $schema->column('causationid')->string();
        // Timestamp of the event publishing
        $schema->column('recordedat')->datetime(6);
        $schema->index(['id'])->unique();
        $schema->index(['stream', 'version'])->unique();
        $schema->index(['correlationid']);
        return $schema;
    }

    public function setup(): void
    {
        $schema = $this->getSchema();
        $schema->save();
    }

    private function getStreamVersion(StreamName $streamName): MaybeVersion
    {
        $result = $this->connection
            ->select('MAX(version)')
            ->from($this->eventTableName)
            ->where('stream', $streamName->value)
            ->run();
        $version = $result->fetchColumn();
        return MaybeVersion::fromVersionOrNull(is_numeric($version) ? Version::fromInteger((int)$version) : null);
    }

    private function commitEvent(StreamName $streamName, Event $event, Version $version): void
    {
        Assert::notEmpty($this->eventTableName);
        $this->connection->insert()
            ->into($this->eventTableName)
            ->values(
                [
                    'id' => $event->id->value,
                    'stream' => $streamName->value,
                    'version' => $version->value,
                    'type' => $event->type->value,
                    'payload' => $event->data->value,
                    'metadata' => $event->metadata?->toJson(),
                    'correlationid' => $event->correlationId?->value,
                    'causationid' => $event->causationId?->value,
                    'recordedat' => $this->clock->now()->format('Y-m-d H:i:s.u'),
                ]
            )
            ->run();
    }

    private function reconnectDatabaseConnection(): void
    {
        try {
            $this->connection->execute('SELECT 1');
        } catch (\Exception $_) {
            $this->connection->getDriver()->disconnect();
            $this->connection->getDriver()->connect();
        }
    }
}
