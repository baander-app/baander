<?php

declare(strict_types=1);

/*
 * Child process of InstantRuntimeConfigurationTest. A fresh process has Doctrine's stock type
 * registry, so it shows what booting the application kernel registers, and opens every kind of
 * connection the application makes to report its session time zone.
 *
 * Prints the outcome as JSON.
 */

use App\Kernel;
use App\Recommendation\Infrastructure\Swoole\RecommendationPoolWorker;
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

$databaseUrl = (string) getenv('DATABASE_URL');
$zones = ['default' => $default->fetchOne('SHOW TimeZone')];
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
    $connection->close();
}
$pdo = (new ReflectionMethod(RecommendationPoolWorker::class, 'createPdo'))->invoke(new RecommendationPoolWorker(), $databaseUrl);
if (!$pdo instanceof PDO) {
    throw new LogicException('The recommendation pool worker opened no PDO connection.');
}
$statement = $pdo->query('SHOW TimeZone');
if ($statement === false) {
    throw new LogicException('SHOW TimeZone failed on the recommendation pool worker connection.');
}
$zones[RecommendationPoolWorker::class] = $statement->fetchColumn();
// bin/worker-lease-agent.php and bin/worker-deployment.php connect through fromParams.
$dedicated = DedicatedConnectionFactory::fromParams((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($databaseUrl));
$zones[DedicatedConnectionFactory::class] = $dedicated->fetchOne('SHOW TimeZone');
$dedicated->close();

echo json_encode([
    'before' => $before,
    'after' => $types(),
    'timestamptz' => $default->getDatabasePlatform()->getDoctrineTypeMapping('timestamptz'),
    'zones' => $zones,
], JSON_THROW_ON_ERROR);
$kernel->shutdown();
