<?php

declare(strict_types=1);

// Disposable host-side controller acceptance; the contained worker fixture does
// not run LeasedWorkerRuntime or consult this database's lease.
require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__, 3) . '/migrations/Version20261002210000.php';
require dirname(__DIR__, 3) . '/migrations/Version20261002220000.php';

use App\Shared\Infrastructure\Worker\DeploymentContainmentController;
use App\Shared\Infrastructure\Worker\DeploymentContainer;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory;
use App\Shared\Infrastructure\Worker\RegisteredDeploymentRecovery;
use App\Shared\Infrastructure\Worker\RegisteredDeploymentStart;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentLease;
use App\Shared\Infrastructure\Worker\DockerWorkerCommand;
use App\Shared\Infrastructure\Worker\DockerWorkerContainment;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261002210000;
use DoctrineMigrations\Version20261002220000;
use Psr\Log\NullLogger;

$connectionParameters = [
    'driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => (int) getenv('WORKER_RECOVERY_PG_PORT'),
    'user' => getenv('POSTGRES_USER'), 'password' => getenv('POSTGRES_PASSWORD'), 'dbname' => getenv('POSTGRES_DB'),
];
$connection = DriverManager::getConnection($connectionParameters);
$observer = DriverManager::getConnection($connectionParameters);
$schema = 'worker_recovery_' . bin2hex(random_bytes(8));
$connection->executeStatement('CREATE SCHEMA ' . $schema);
try {
    $connection->executeStatement('SET search_path TO ' . $schema);
    foreach ([Version20261002210000::class, Version20261002220000::class] as $migrationClass) {
        $migration = new $migrationClass($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
    $leases = new DoctrineDeploymentLease($connection);
    $command = new DockerWorkerCommand($argv[1], $argv[2]);
    $containment = new DockerWorkerContainment($command->execute(...));
    $controller = new DeploymentContainmentController($leases, $containment->retire(...));
    $namespace = 'baander.app:recovery-test';
    $boot = (string) getenv('WORKER_RECOVERY_BOOT_ID');
    $replacementBoot = str_repeat('b', 32);
    $inventory = new DoctrineDeploymentInventory($connection);
    $daemonId = trim($command->execute(['info', '--format', '{{.ID}}']));
    $observer->executeStatement('SET search_path TO ' . $schema);
    $binding = new DeploymentContainer($namespace, $boot, $daemonId, $argv[3]);
    $state = json_decode($command->execute(['container', 'inspect', '--format', '{"status":{{json .State.Status}},"pid":{{json .State.Pid}}}', $binding->containerId]), true, flags: JSON_THROW_ON_ERROR);
    if ($state !== ['status' => 'created', 'pid' => 0]) {
        throw new RuntimeException('Predecessor had process activity before registered startup.');
    }
    $startCalls = 0;
    $starter = new RegisteredDeploymentStart($inventory, static function (array $arguments) use ($command, $observer, $binding, &$startCalls): string {
        if ($arguments === ['container', 'start', $binding->containerId]) {
            // Independent connection visibility proves registration and one-shot
            // admission committed BEFORE Docker starts the first worker process.
            $committed = $observer->fetchOne('SELECT count(*) FROM worker_deployment_containers WHERE namespace = :namespace AND boot_id = :boot AND daemon_id = :daemon AND container_id = :container AND start_claimed_at IS NOT NULL',
                ['namespace' => $binding->namespace, 'boot' => $binding->bootId, 'daemon' => $binding->daemonId, 'container' => $binding->containerId]);
            if ((int) $committed !== 1) {
                throw new RuntimeException('Start attempted before committed inventory and claim.');
            }
            ++$startCalls;
        }
        return $command->execute($arguments);
    });
    if (!$starter->start($binding) || $startCalls !== 1) {
        throw new RuntimeException('Registered initial startup was not admitted once.');
    }
    $readyDeadline = hrtime(true) / 1e9 + 5;
    do {
        try {
            $command->execute(['container', 'exec', $binding->containerId, 'test', '-s', '/tmp/baander-descendant-ready']);
            break;
        } catch (RuntimeException) {
            if (hrtime(true) / 1e9 >= $readyDeadline) {
                throw new RuntimeException('Registered worker descendant failed to become ready.');
            }
            usleep(100_000);
        }
    } while (true);
    $firstPid = trim($command->execute(['container', 'inspect', '--format', '{{.State.Pid}}', $binding->containerId]));
    try {
        if ($starter->start($binding)) {
            throw new LogicException('A repeated controller start was admitted.');
        }
    } catch (RuntimeException) {
        // Created-state validation rejects repeating a start on a running container.
    }
    if ($startCalls !== 1 || trim($command->execute(['container', 'inspect', '--format', '{{.State.Pid}}', $binding->containerId])) !== $firstPid) {
        throw new RuntimeException('Repeated startup issued another start or changed the predecessor PID.');
    }
    echo "PASS: committed inventory and one-shot claim precede initial process activity; repeated start refused\n";
    // The mechanical namespace fixture does not consult a lease. The controller
    // acquires the real database lease AFTER its registered startup acceptance.
    $registeredRecovery = new RegisteredDeploymentRecovery($inventory, $leases, $command->execute(...));
    $initial = $leases->acquire($namespace, $boot, 60);
    if ($initial === null || $initial->epoch !== 1) {
        throw new RuntimeException('Initial committed acquisition failed.');
    }
    // Test-only deterministic expiry using the database clock, not a wall-clock sleep.
    $connection->executeStatement("UPDATE worker_deployment_leases SET expires_at = clock_timestamp() - interval '1 second' WHERE namespace = :namespace", ['namespace' => $namespace]);
    if ($leases->acquire($namespace, $replacementBoot, 60) !== null) {
        throw new RuntimeException('Expired ownership incorrectly admitted a replacement.');
    }
    if ($controller->recover($namespace, $replacementBoot, $argv[3])) {
        throw new RuntimeException('Wrong database boot unexpectedly authorized retirement.');
    }
    try {
        $controller->recover($namespace, $boot, $argv[4]);
        throw new LogicException('Mismatched Docker label unexpectedly authorized retirement.');
    } catch (RuntimeException) {
        // Adapter rejection is expected; a sentinel LogicException cannot be swallowed here.
    }
    foreach ([$argv[3], $argv[4]] as $id) {
        if (trim($command->execute(['container', 'inspect', '--format', '{{.State.Running}}', $id])) !== 'true') {
            throw new RuntimeException('Rejected retirement modified a live container.');
        }
    }
    $reserved = $leases->findForContainment($namespace);
    if ($reserved === null || $reserved->bootId !== $boot || $reserved->epoch !== 1
        || $leases->acquire($namespace, $replacementBoot, 60) !== null) {
        throw new RuntimeException('Rejected retirement changed committed ownership.');
    }
    echo "PASS: expiry and mismatched boots preserve live predecessor and committed reservation\n";
    if (!$registeredRecovery->recover($namespace, $boot)) {
        throw new RuntimeException('Successful immutable-ID removal did not release committed ownership.');
    }
    $replacement = $leases->acquire($namespace, $replacementBoot, 60);
    if ($replacement === null || $replacement->epoch !== 2 || $leases->renew($initial, 60)
        || $leases->acknowledgeContainment($initial)) {
        throw new RuntimeException('Recovery did not advance epoch or reject old tokens.');
    }
    if ($inventory->register(new DeploymentContainer($namespace, $replacementBoot, $daemonId, $argv[3]))) {
        throw new RuntimeException('Removed immutable container ID was rebound to a replacement boot.');
    }
    if (!$inventory->register(new DeploymentContainer($namespace, $replacementBoot, $daemonId, $argv[4]))) {
        throw new RuntimeException('Replacement deployment inventory registration failed.');
    }
    try {
        $command->execute(['container', 'start', $argv[3]]);
        throw new LogicException('Removed immutable predecessor ID could be restarted.');
    } catch (RuntimeException) {
    }
    echo "PASS: registered deployment removal permits epoch 2, rejects immutable-ID rebinding and fences old lease operations\n";
    try {
        $controller->recover($namespace, $replacementBoot, $argv[3]);
        throw new LogicException('Absent predecessor supplied a retirement receipt.');
    } catch (RuntimeException) {
    }
    $reserved = $leases->findForContainment($namespace);
    if ($reserved === null || $reserved->bootId !== $replacementBoot || $reserved->epoch !== 2
        || $leases->acquire($namespace, str_repeat('c', 32), 60) !== null) {
        throw new RuntimeException('Absent-container failure released active replacement ownership.');
    }
    echo "PASS: absent retirement keeps replacement lease reserved\n";
    echo 'PostgreSQL ' . $connection->fetchOne('SHOW server_version') . ": combined controller recovery verified\n";
} finally {
    $connection->executeStatement('SET search_path TO public');
    $connection->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
    $observer->close();
    $connection->close();
}
