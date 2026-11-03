<?php

declare(strict_types=1);

/*
 * Child process of InstantRuntimeConfigurationTest. A fresh process has Doctrine's stock type
 * registry, so it shows what booting the application kernel registers, and opens every kind of
 * connection the application makes to report its session time zone and how many named
 * statements a live prepared statement leaves on the server.
 *
 * Prints the outcome as JSON.
 */

use App\Kernel;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerManualOccurrenceRecorder;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceDispatchStore;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceExecutionStore;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerOccurrenceMaterializer;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerRecoveryScheduleStore;
use App\Scheduler\Infrastructure\Doctrine\DoctrineSchedulerWorkerAuthority;
use App\Shared\Infrastructure\Doctrine\DedicatedConnectionFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Dotenv\Dotenv;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
(new Dotenv())->bootEnv($root . '/.env');

$types = static fn (): array => [
    Types::DATETIME_IMMUTABLE => Type::getType(Types::DATETIME_IMMUTABLE)::class,
    Types::DATETIMETZ_IMMUTABLE => Type::getType(Types::DATETIMETZ_IMMUTABLE)::class,
];
$before = $types();

$kernel = new Kernel('test', false);
$kernel->boot();
$default = $kernel->getContainer()->get('doctrine')->getConnection();
if (!$default instanceof Connection) {
    throw new LogicException('The default connection is not a DBAL connection.');
}

$named = static function (Connection $connection): int {
    $statement = $connection->prepare('SELECT ?::int');
    $statement->bindValue(1, 1);
    $result = $statement->executeQuery();
    $count = (int) $connection->fetchOne('SELECT count(*) FROM pg_prepared_statements');
    $result->free();
    unset($statement);

    return $count;
};
$databaseUrl = (string) getenv('DATABASE_URL');
$zones = ['default' => $default->fetchOne('SHOW TimeZone')];
$statements = ['default' => $named($default)];
foreach ([
    DoctrineSchedulerManualOccurrenceRecorder::class,
    DoctrineSchedulerOccurrenceDispatchStore::class,
    DoctrineSchedulerOccurrenceExecutionStore::class,
    DoctrineSchedulerOccurrenceMaterializer::class,
    DoctrineSchedulerRecoveryScheduleStore::class,
    DoctrineSchedulerWorkerAuthority::class,
] as $store) {
    // Each store keeps its connection private; read it to ask the session it opens.
    $connection = (new ReflectionProperty($store, 'connection'))->getValue($store::fromDsn($databaseUrl));
    if (!$connection instanceof Connection) {
        throw new LogicException($store . ' holds no DBAL connection.');
    }
    $zones[$store] = $connection->fetchOne('SHOW TimeZone');
    $statements[$store] = $named($connection);
    $connection->close();
}
// bin/worker-lease-agent.php and bin/worker-deployment.php connect through fromParams.
$params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($databaseUrl);
$dedicated = DedicatedConnectionFactory::fromParams($params);
$zones[DedicatedConnectionFactory::class] = $dedicated->fetchOne('SHOW TimeZone');
$statements[DedicatedConnectionFactory::class] = $named($dedicated);
$dedicated->close();
// A caller cannot turn named statements back on for a dedicated connection.
$requested = DedicatedConnectionFactory::fromParams($params + ['driverOptions' => [Pdo\Pgsql::ATTR_DISABLE_PREPARES => false]]);
$statements['requested named statements'] = $named($requested);
$requested->close();

echo json_encode([
    'before' => $before,
    'after' => $types(),
    'timestamptz' => $default->getDatabasePlatform()->getDoctrineTypeMapping('timestamptz'),
    'zones' => $zones,
    'statements' => $statements,
], JSON_THROW_ON_ERROR);
$kernel->shutdown();
