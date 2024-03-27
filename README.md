# Cycle ORM adapter for the `neos/eventstore` package

Database Adapter implementation for the [neos/eventstore](https://github.com/neos/eventstore) package.
It is essentially an adaption of the [Doctrine adapter](https://github.com/neos/eventstore-doctrineadapter) for [Cycle ORM](https://cycle-orm.dev/) / the [Spiral framework](https://spiral.dev/).

## Usage

Install via [composer](https://getcomposer.org):

```shell
composer require technoly/neos-eventstore-cycleadapter
```

### Create an instance

To create a `CycleEventStore`, an instance of `\Cycle\Database\DatabaseInterface` is required. It can be
obtained via the DatabaseManager or configured in your Spiral bootloader if you are using the Spiral framework.

See [Cycle documentation](https://cycle-orm.dev/docs/database-connect/current/en#instantiate-dbal) for more details.

With that, an Event Store instance can be created:

```php
use Technoly\NeosEventStore\CycleAdapter\CycleEventStore;

$eventTableName = 'some_namespace_events';
$eventStore = new CycleEventStore($connection, $eventTableName);
```

See [README](https://github.com/neos/eventstore/blob/main/README.md#usage) of the `neos/eventstore` package for details on how to write and read events.

## Contribution

Contributions in the form of [issues](https://github.com/technoly/neos-eventstore-cycleadapter/issues) or [pull requests](https://github.com/technoly/neos-eventstore-cycleadapter/pulls) are highly appreciated.

## License

See [LICENSE](./LICENSE)
