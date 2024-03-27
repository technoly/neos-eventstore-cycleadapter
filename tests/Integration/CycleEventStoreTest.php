<?php

declare(strict_types=1);

namespace Technoly\NeosEventStore\CycleAdapter\Tests\Integration;

use Cycle\Database\Config;
use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\Database;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use Cycle\Database\Driver\MySQL\MySQLDriver;
use Cycle\Database\Driver\Postgres\PostgresDriver;
use Cycle\Database\Driver\SQLite\SQLiteDriver;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\EventStore\StatusType;
use Neos\EventStore\Tests\Integration\AbstractEventStoreTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use Technoly\NeosEventStore\CycleAdapter\CycleEventStore;
use Webmozart\Assert\Assert;

#[CoversClass(CycleEventStore::class)]
final class CycleEventStoreTest extends AbstractEventStoreTestBase
{
    private static ?DatabaseInterface $connection = null;

    protected static function createEventStore(): EventStoreInterface
    {
        return new CycleEventStore(self::connection(), self::eventTableName());
    }

    protected static function resetEventStore(): void
    {
        $connection = self::connection();
        Assert::notEmpty(self::eventTableName());
        if (!$connection->hasTable(self::eventTableName())) {
            return;
        }
        if ($connection->getDriver() instanceof PostgresDriver) {
            $connection->execute('TRUNCATE TABLE ' . self::eventTableName() . ' RESTART IDENTITY');
        } else {
            $connection->execute('TRUNCATE TABLE ' . self::eventTableName());
        }
    }

    public static function connection(): DatabaseInterface
    {
        if (self::$connection === null) {
            $dsn = getenv('DB_DSN');
            $user = getenv('DB_USER') ?: 'db';
            $password = getenv('DB_PASSWORD') ?: 'db';
            if (!is_string($dsn)) {
                throw new \Exception('DB_DSN environment variable not set.', 1711532889723);
            }
            self::$connection = self::getDatabase($dsn, $user, $password);
        }
        return self::$connection;
    }

    public static function getDatabase(string $dsn, ?string $user = null, ?string $password = null): DatabaseInterface
    {
        Assert::notEmpty($dsn);
        $type = explode(':', $dsn)[0];
        $driver = match ($type) {
            'sqlite' => SQLiteDriver::create(
                new SQLiteDriverConfig(
                    new Config\SQLite\DsnConnectionConfig(
                        $dsn
                    )
                )
            ),
            'mysql' => MySQLDriver::create(
                new Config\MySQLDriverConfig(
                    new Config\MySQL\DsnConnectionConfig(
                        $dsn,
                        $user ?: null,
                        $password ?: null
                    )
                )
            ),
            'pgsql' => PostgresDriver::create(
                new Config\PostgresDriverConfig(
                    new Config\Postgres\DsnConnectionConfig(
                        $dsn,
                        $user ?: null,
                        $password ?: null
                    )
                )
            ),
            default =>  throw new \Exception('Invalid DSN config', 1711451680530),
        };
        $dbal = new DatabaseManager(new DatabaseConfig());
        $dbal->addDatabase(
            new Database(
                'test',
                '',
                $driver
            )
        );
        return $dbal->database('test');
    }

    public static function eventTableName(): string
    {
        return 'events_test';
    }

    public function test_setup_throws_exception_if_database_connection_fails(): void
    {
        $connection = self::getDatabase('mysql:invalid-connection');
        $eventStore = new CycleEventStore($connection, self::eventTableName());

        $this->expectException(\RuntimeException::class);
        $eventStore->setup();
    }

    public function test_status_returns_error_status_if_database_connection_fails(): void
    {
        $connection = self::getDatabase('mysql:invalid-connection');
        $eventStore = new CycleEventStore($connection, self::eventTableName());
        self::assertSame(StatusType::ERROR, $eventStore->status()->type);
    }
}
