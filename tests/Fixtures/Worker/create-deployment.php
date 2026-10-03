<?php

declare(strict_types=1);

// Disposable host controller drill. The created PHP sleeper has no application
// handlers or lease runtime; this covers real create/start/recovery protocols only.
require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__, 3) . '/migrations/Version20261002210000.php';
require dirname(__DIR__, 3) . '/migrations/Version20261002220000.php';
require dirname(__DIR__, 3) . '/migrations/Version20261003020000.php';

use App\Shared\Infrastructure\Worker\DeploymentContainerRecipe;
use App\Shared\Infrastructure\Worker\DeploymentRuntimeEnvironment;
use App\Shared\Infrastructure\Worker\DockerDeploymentCreate;
use App\Shared\Infrastructure\Worker\DockerWorkerCommand;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentLease;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentRetirement;
use App\Shared\Infrastructure\Worker\RegisteredDeploymentRecovery;
use App\Shared\Infrastructure\Worker\RegisteredDeploymentStart;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261002210000;
use DoctrineMigrations\Version20261002220000;
use DoctrineMigrations\Version20261003020000;
use Psr\Log\NullLogger;

if (PHP_SAPI !== 'cli' || !isset($argv) || count($argv) !== 4) {
    throw new InvalidArgumentException('Expected Docker executable, local endpoint and immutable image arguments.');
}

$params = ['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => (int) getenv('WORKER_RECOVERY_PG_PORT'),
    'user' => getenv('POSTGRES_USER'), 'password' => getenv('POSTGRES_PASSWORD'), 'dbname' => getenv('POSTGRES_DB')];
$connection = DriverManager::getConnection($params);
$observer = DriverManager::getConnection($params);
$schema = 'worker_create_' . bin2hex(random_bytes(8));
$command = new DockerWorkerCommand($argv[1], $argv[2]);
$namespace = 'baander.app:create-test';
$boot = bin2hex(random_bytes(16));
$name = RegisteredDeploymentStart::containerName($namespace, $boot);
$createdId = null;
$preleaseId = null;
$preleaseName = null;
$connection->executeStatement('CREATE SCHEMA ' . $schema);
try {
    foreach ([$connection, $observer] as $session) {
        $session->executeStatement('SET search_path TO ' . $schema);
    }
    foreach ([Version20261002210000::class, Version20261002220000::class, Version20261003020000::class] as $migrationClass) {
        $migration = new $migrationClass($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
    $daemon = trim($command->execute(['info', '--format', '{{.ID}}']));
    $runtime = new DeploymentRuntimeEnvironment(['APP_ENV' => 'prod', 'APP_DEBUG' => '0',
        'APP_SECRET' => 'test-only-literal $HOME `ignored` = ' . bin2hex(random_bytes(16)),
        'DATABASE_URL' => 'postgresql://test-only:isolated@database.baander.app/unused']);
    $recipe = new DeploymentContainerRecipe($namespace, $boot, $daemon, $argv[3],
        ['/usr/local/bin/php', '-r', 'while (true) { usleep(10000); }'], 'none', 64 * 1024 * 1024, 1_000_000_000, 32, $runtime);
    $inventory = new DoctrineDeploymentInventory($connection);
    $leases = new DoctrineDeploymentLease($connection);
    $createCalls = 0;
    // Callback mutations occur behind the adapter. Observe the count anew at
    // each boundary instead of treating an earlier value as a lasting invariant.
    $assertSingleCreate = static function (int $calls): void {
        if ($calls !== 1) {
            throw new RuntimeException('Expected exactly one Docker creation attempt.');
        }
    };
    $lostReply = new RuntimeException('Simulated lost Docker create reply after actual creation.');
    $executor = static function (array $arguments) use ($command, $observer, $recipe, $lostReply, &$createdId, &$createCalls): string {
        if (array_slice($arguments, 0, 2) === ['container', 'create']) {
            $committed = $observer->fetchOne('SELECT count(*) FROM worker_deployment_creations WHERE namespace = :namespace AND boot_id = :boot AND daemon_id = :daemon AND recipe_hash = :recipe',
                ['namespace' => $recipe->namespace, 'boot' => $recipe->bootId, 'daemon' => $recipe->daemonId, 'recipe' => $recipe->fingerprint()]);
            if ((int) $committed !== 1) {
                throw new LogicException('Docker create attempted before committed creation intent.');
            }
            ++$createCalls;
            $createdId = trim($command->execute($arguments));
            throw $lostReply;
        }
        return $command->execute($arguments);
    };
    try {
        (new DockerDeploymentCreate($inventory, $executor))->create($recipe);
        throw new LogicException('Lost create reply unexpectedly registered a binding.');
    } catch (RuntimeException $error) {
        if ($error !== $lostReply) {
            throw $error;
        }
    }
    $assertSingleCreate($createCalls);
    if ($createdId === null || $inventory->find($namespace, $boot) !== null || !$inventory->matchesCreate($recipe)) {
        throw new RuntimeException('Lost reply failed to preserve committed intent without a binding.');
    }
    $fresh = new DockerDeploymentCreate($inventory, $executor);
    try {
        $fresh->create($recipe);
        throw new LogicException('Fresh adapter retried uncertain Docker creation.');
    } catch (RuntimeException) {
    }
    $assertSingleCreate($createCalls);
    $different = new DeploymentContainerRecipe($namespace, $boot, $daemon, $recipe->imageId, $recipe->command,
        $recipe->network, $recipe->memoryBytes + 16 * 1024 * 1024, $recipe->nanoCpus, $recipe->pidsLimit, $runtime);
    try {
        $fresh->reconcile($different);
        throw new LogicException('Different recipe reconciled a reserved boot.');
    } catch (RuntimeException) {
    }
    if ((new DoctrineDeploymentInventory($observer))->find($namespace, $boot) !== null) {
        throw new RuntimeException('Mismatched recipe created an inventory binding.');
    }
    $binding = $fresh->reconcile($recipe);
    $assertSingleCreate($createCalls);
    if ($binding->containerId !== $createdId) {
        throw new RuntimeException('Reconciliation did not bind the originally created immutable ID.');
    }
    $state = json_decode($command->execute(['container', 'inspect', '--format', '{"status":{{json .State.Status}},"pid":{{json .State.Pid}}}', $createdId]), true, flags: JSON_THROW_ON_ERROR);
    if ($state !== ['status' => 'created', 'pid' => 0]) {
        throw new RuntimeException('Create or reconciliation started a process automatically.');
    }
    echo "PASS: durable create intent survives lost reply; recreation and mismatched recipe refused; exact-name reconciliation remains unstarted\n";
    if (!(new RegisteredDeploymentStart($inventory, $command->execute(...)))->start($binding)) {
        throw new RuntimeException('Reconciled deployment could not receive one registered start.');
    }
    if (trim($command->execute(['container', 'inspect', '--format', '{{.State.Running}}', $createdId])) !== 'true') {
        throw new RuntimeException('Registered recipe process did not start.');
    }
    // Only a digest crosses the observer output: never print configured values.
    // This network-none container cannot contact the test-only database hostname.
    $observed = trim($command->execute(['container', 'exec', $createdId, '/usr/local/bin/php', '-r',
        'echo hash("sha256", json_encode(array_map(getenv(...), ["APP_DEBUG", "APP_ENV", "APP_SECRET", "DATABASE_URL"]), JSON_THROW_ON_ERROR));']));
    $expected = hash('sha256', json_encode(array_values($runtime->variables), JSON_THROW_ON_ERROR));
    if (!hash_equals($expected, $observed)) {
        throw new RuntimeException('Docker did not preserve admitted runtime configuration literally.');
    }
    echo "PASS: private env-file preserves literal runtime configuration through real Docker creation and reconciliation\n";
    // Lease acquisition here verifies the external controller protocol, not the
    // sleeper's admission or LeasedWorkerRuntime integration.
    $lease = $leases->acquire($namespace, $boot, 60);
    if ($lease === null) {
        throw new RuntimeException('Fixture owner could not acquire its lease.');
    }
    $retirements = new DoctrineDeploymentRetirement($connection);
    $lostRemoval = new RuntimeException('Simulated lost successful Docker removal reply.');
    $uncertainRecovery = new RegisteredDeploymentRecovery($inventory, $retirements,
        static function (array $arguments) use ($command, $lostRemoval): string {
            $result = $command->execute($arguments);
            if (array_slice($arguments, 0, 3) === ['container', 'rm', '--force']) {
                throw $lostRemoval;
            }
            return $result;
        });
    try {
        $uncertainRecovery->recover($namespace, $boot);
        throw new LogicException('Lost removal reply unexpectedly completed retirement.');
    } catch (RuntimeException $error) {
        if ($error !== $lostRemoval) {
            throw $error;
        }
    }
    if ((new DoctrineDeploymentLease($observer))->findForContainment($namespace) != $lease
        || (new DoctrineDeploymentRetirement($observer))->find($namespace, $boot)?->completed !== false) {
        throw new RuntimeException('Uncertain removal did not preserve ownership and pending intent.');
    }
    $registeredRecovery = new RegisteredDeploymentRecovery($inventory, $retirements, $command->execute(...));
    if (!$registeredRecovery->recover($namespace, $boot)) {
        throw new RuntimeException('Registered recovery did not remove and release the created deployment.');
    }
    $replacement = $leases->acquire($namespace, bin2hex(random_bytes(16)), 60);
    if ($replacement === null || $replacement->epoch !== 2) {
        throw new RuntimeException('Created deployment recovery did not permit epoch 2.');
    }
    // A never-started binding has no lease, but still needs permanent retirement.
    $preleaseBoot = bin2hex(random_bytes(16));
    $preleaseName = RegisteredDeploymentStart::containerName($namespace, $preleaseBoot);
    $preleaseRecipe = new DeploymentContainerRecipe($namespace, $preleaseBoot, $daemon, $recipe->imageId,
        $recipe->command, $recipe->network, $recipe->memoryBytes, $recipe->nanoCpus, $recipe->pidsLimit, $runtime);
    $preleaseId = (new DockerDeploymentCreate($inventory, $command->execute(...)))->create($preleaseRecipe)->containerId;
    if (!$registeredRecovery->recover($namespace, $preleaseBoot)
        || !$registeredRecovery->recover($namespace, $boot)
        || (new DoctrineDeploymentLease($observer))->findForContainment($namespace) != $replacement) {
        throw new RuntimeException('Prelease or repeated retirement modified another boot ownership.');
    }
    if ($leases->acquire($namespace, $preleaseBoot, 60) !== null) {
        throw new RuntimeException('Retired boot unexpectedly acquired ownership.');
    }
    echo "PASS: lost removal reply reconciles by durable intent; prelease retirement and completed retries preserve replacement ownership\n";
    echo "PASS: reconciled immutable recipe starts once; real registered removal releases ownership for epoch 2\n";
} finally {
    // A lost create reply can leave only a known deterministic name. Cleanup must
    // cover that case as well as the full immutable ID learned by this harness.
    try {
        $command->execute(['container', 'rm', '--force', $createdId ?? $name]);
    } catch (RuntimeException) {
        // A successfully recovered container is already absent.
    }
    if ($preleaseName !== null) {
        try {
            $command->execute(['container', 'rm', '--force', $preleaseId ?? $preleaseName]);
        } catch (RuntimeException) {
        }
    }
    $observer->close();
    $connection->executeStatement('SET search_path TO public');
    $connection->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
    $connection->close();
}
