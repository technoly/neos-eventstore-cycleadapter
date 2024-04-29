<?php

declare(strict_types=1);

namespace Technoly\NeosEventStore\CycleAdapter;

use Cycle\Database\Query\SelectQuery;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\CausationId;
use Neos\EventStore\Model\Event\CorrelationId;
use Neos\EventStore\Model\Event\EventData;
use Neos\EventStore\Model\Event\EventId;
use Neos\EventStore\Model\Event\EventMetadata;
use Neos\EventStore\Model\Event\EventType;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\EventStream\EventStreamInterface;

final class CycleEventStream implements EventStreamInterface
{
    // @phpstan-ignore-next-line
    private function __construct(
        private SelectQuery $selectQuery,
        private readonly ?SequenceNumber $minimumSequenceNumber,
        private readonly ?SequenceNumber $maximumSequenceNumber,
        private readonly ?int $limit,
        private readonly bool $backwards,
    ) {
    }

    // @phpstan-ignore-next-line
    public static function create(SelectQuery $selectQuery): self
    {
        return new self($selectQuery, null, null, null, false);
    }

    public function withMinimumSequenceNumber(SequenceNumber $sequenceNumber): self
    {
        if ($this->minimumSequenceNumber !== null && $sequenceNumber->value === $this->minimumSequenceNumber->value) {
            return $this;
        }
        return new self(
            $this->selectQuery,
            $sequenceNumber,
            $this->maximumSequenceNumber,
            $this->limit,
            $this->backwards
        );
    }

    public function withMaximumSequenceNumber(SequenceNumber $sequenceNumber): self
    {
        if ($this->maximumSequenceNumber !== null && $sequenceNumber->value === $this->maximumSequenceNumber->value) {
            return $this;
        }
        return new self(
            $this->selectQuery,
            $this->minimumSequenceNumber,
            $sequenceNumber,
            $this->limit,
            $this->backwards
        );
    }

    public function limit(int $limit): self
    {
        if ($limit === $this->limit) {
            return $this;
        }
        return new self(
            $this->selectQuery,
            $this->minimumSequenceNumber,
            $this->maximumSequenceNumber,
            $limit,
            $this->backwards
        );
    }

    public function backwards(): self
    {
        if ($this->backwards) {
            return $this;
        }
        return new self(
            $this->selectQuery,
            $this->minimumSequenceNumber,
            $this->maximumSequenceNumber,
            $this->limit,
            true
        );
    }

    public function getIterator(): \Traversable
    {
        $selectQuery = clone $this->selectQuery;
        if ($this->minimumSequenceNumber !== null) {
            $selectQuery = $selectQuery->andWhere('sequencenumber', '>=', $this->minimumSequenceNumber->value);
        }
        if ($this->maximumSequenceNumber !== null) {
            $selectQuery = $selectQuery->andWhere('sequencenumber', '<=', $this->maximumSequenceNumber->value);
        }
        if ($this->limit !== null) {
            $selectQuery = $selectQuery->limit($this->limit);
        }
        $selectQuery = $selectQuery->orderBy(
            'sequencenumber',
            $this->backwards ? SelectQuery::SORT_DESC : SelectQuery::SORT_ASC
        );

        $this->reconnectDatabaseConnection();

        $result = $selectQuery->run();
        /** @var array<string, string> $row */
        foreach ($result->fetchAll() as $row) {
            $recordedAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $row['recordedat']);
            if ($recordedAt === false) {
                throw new \RuntimeException(
                    sprintf('Failed to parse "recordetat" value of "%s" in event "%s"', $row['recordedat'], $row['id']),
                    1651744355
                );
            }
            yield new EventEnvelope(
                new Event(
                    EventId::fromString($row['id']),
                    EventType::fromString($row['type']),
                    EventData::fromString($row['payload']),
                    isset($row['metadata']) ? EventMetadata::fromJson($row['metadata']) : null,
                    isset($row['causationid']) ? CausationId::fromString($row['causationid']) : null,
                    isset($row['correlationid']) ? CorrelationId::fromString($row['correlationid']) : null,
                ),
                StreamName::fromString($row['stream']),
                Version::fromInteger((int)$row['version']),
                SequenceNumber::fromInteger((int)$row['sequencenumber']),
                $recordedAt
            );
        }
    }

    // -----------------------------------

    private function reconnectDatabaseConnection(): void
    {
        try {
            $this->selectQuery->getDriver()?->execute('SELECT 1');
        } catch (\Exception $_) {
            $this->selectQuery->getDriver()->disconnect();
            $this->selectQuery->getDriver()->connect();
        }
    }
}
