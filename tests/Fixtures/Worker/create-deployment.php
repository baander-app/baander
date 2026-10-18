<?php

declare(strict_types=1);

// Disposable host controller protocol drill; children do not run application handlers.
require dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/Support/FinalSchemaBootstrap.php';

use App\Shared\Application\DTO\DeploymentUnit;
use App\Shared\Infrastructure\Worker\DeploymentContainerRecipe;
use App\Shared\Infrastructure\Worker\DeploymentRuntimeEnvironment;
use App\Shared\Infrastructure\Worker\DockerDeploymentCreate;
use App\Shared\Infrastructure\Worker\DockerWorkerCommand;
use App\Shared\Infrastructure\Worker\DockerWorkerContainment;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentLease;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentRetirement;
use App\Shared\Infrastructure\Worker\RegisteredDeploymentRecovery;
use App\Shared\Infrastructure\Worker\RegisteredDeploymentStart;
use Doctrine\DBAL\DriverManager;

$arguments = $_SERVER['argv'] ?? [];
if (PHP_SAPI !== 'cli' || count($arguments) !== 4) {
    throw new InvalidArgumentException('Expected Docker executable, local endpoint and immutable image arguments.');
}
$params = ['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => (int) getenv('WORKER_RECOVERY_PG_PORT'),
    'user' => getenv('POSTGRES_USER'), 'password' => getenv('POSTGRES_PASSWORD'), 'dbname' => getenv('POSTGRES_DB')];
$connection = DriverManager::getConnection($params);
$observer = DriverManager::getConnection($params);
$command = new DockerWorkerCommand($arguments[1], $arguments[2]);
$namespace = 'baander.app:paired-controller-' . bin2hex(random_bytes(8));
$schema = 'worker_create_' . bin2hex(random_bytes(8));
$ipcVolume = 'baander-worker-create-' . bin2hex(random_bytes(8));
$ownedIds = [];
$connection->executeStatement('CREATE SCHEMA ' . $schema);
try {
    foreach ([$connection, $observer] as $session) {
        $session->executeStatement('SET search_path TO ' . $schema . ', public');
    }
    \App\Tests\Support\FinalSchemaBootstrap::bootstrap($connection);
    $daemon = trim($command->execute(['info', '--format', '{{.ID}}']));
    $inventory = new DoctrineDeploymentInventory($connection);
    $leases = new DoctrineDeploymentLease($connection);
    $retirements = new DoctrineDeploymentRetirement($connection);
    $runtime = new DeploymentRuntimeEnvironment(['APP_ENV' => 'prod', 'APP_DEBUG' => '0',
        'APP_SECRET' => 'test-only-literal $HOME `ignored` = ' . bin2hex(random_bytes(16)),
        'DATABASE_URL' => 'postgresql://test-only:isolated@database.baander.app/unused']);
    // A descendant ignores TERM and leaves a heartbeat. Immutable removal must
    // contain the whole private PID namespace, including this descendant.
    $sleeper = 'pcntl_async_signals(true); if (pcntl_fork() === 0) { pcntl_signal(SIGTERM, SIG_IGN);'
        . 'file_put_contents("/tmp/baander-descendant-ready", (string) getmypid());'
        . 'while (true) { file_put_contents("/tmp/baander-descendant-heartbeat", (string) hrtime(true)); usleep(10000); }}'
        . 'while (true) { usleep(10000); }';
    $recipesFor = static function (string $boot) use ($namespace, $daemon, $arguments, $runtime, $ipcVolume, $sleeper): array {
        $recipes = [];
        foreach (DeploymentUnit::cases() as $unit) {
            $recipes[$unit->value] = new DeploymentContainerRecipe($namespace, $boot, $daemon, $arguments[3],
                ['/usr/local/bin/php', '-r', $sleeper], 'none', 64 * 1024 * 1024, 1_000_000_000, 32,
                $unit, [$unit === DeploymentUnit::Control ? 0 : 1], $ipcVolume, $runtime);
        }
        return $recipes;
    };
    $reserve = static function (string $boot, array $recipes) use ($inventory, $namespace, $daemon): void {
        if (!$inventory->reserveBoot($namespace, $boot, hash('sha256', $boot), $daemon,
            $recipes['control']->fingerprint(), $recipes['execution']->fingerprint())) {
            throw new RuntimeException('Could not reserve the fixed deployment pair.');
        }
    };
    $start = static function ($binding, $recipe) use ($inventory, $command, $observer): bool {
        return (new RegisteredDeploymentStart($inventory, static function (array $args) use ($command, $observer, $binding): string {
            if ($args === ['container', 'start', $binding->containerId]) {
                $committed = $observer->fetchOne('SELECT count(*) FROM worker_deployment_containers WHERE namespace = :namespace AND boot_id = :boot AND unit = :unit AND container_id = :container AND start_claimed_at IS NOT NULL',
                    ['namespace' => $binding->namespace, 'boot' => $binding->bootId, 'unit' => $binding->unit->value, 'container' => $binding->containerId]);
                if ((int) $committed !== 1) {
                    throw new RuntimeException('Docker startup preceded the committed unit claim.');
                }
            }
            return $command->execute($args);
        }, $recipe))->start($binding);
    };
    $creator = new DockerDeploymentCreate($inventory, $command->execute(...));
    $boot = bin2hex(random_bytes(16));
    $recipes = $recipesFor($boot);
    $reserve($boot, $recipes);
    $createCalls = 0;
    $lostReply = new RuntimeException('Simulated lost Docker create reply.');
    $uncertainCreator = new DockerDeploymentCreate($inventory, static function (array $args) use ($command, $observer, $namespace, $boot, &$ownedIds, &$createCalls, $lostReply): string {
        if (array_slice($args, 0, 2) === ['container', 'create']) {
            if ((int) $observer->fetchOne("SELECT count(*) FROM worker_deployment_creations WHERE namespace = :namespace AND boot_id = :boot AND unit = 'control' AND claimed_at IS NOT NULL", ['namespace' => $namespace, 'boot' => $boot]) !== 1) {
                throw new RuntimeException('Docker create preceded committed creation claim.');
            }
            ++$createCalls;
            $ownedIds[] = trim($command->execute($args));
            throw $lostReply;
        }
        return $command->execute($args);
    });
    try {
        $uncertainCreator->create($recipes['control']);
        throw new LogicException('Lost create reply unexpectedly bound a unit.');
    } catch (RuntimeException $error) {
        if ($error !== $lostReply) { throw $error; }
    }
    try {
        $uncertainCreator->create($recipes['control']);
        throw new LogicException('Uncertain create was retried.');
    } catch (RuntimeException) {
    }
    if ($createCalls !== 1 || $inventory->findUnit($namespace, $boot, DeploymentUnit::Control) !== null) {
        throw new RuntimeException('Lost reply failed to preserve one irreversible create claim.');
    }
    $original = $recipes['control'];
    $different = new DeploymentContainerRecipe($namespace, $boot, $daemon, $original->imageId,
        $original->command, $original->network, $original->memoryBytes + 16 * 1024 * 1024,
        $original->nanoCpus, $original->pidsLimit, $original->unit, $original->cpuSet, $ipcVolume, $runtime);
    try {
        $creator->reconcile($different);
        throw new LogicException('A changed recipe reconciled the reserved unit.');
    } catch (RuntimeException) {
    }
    $control = $creator->reconcile($recipes['control']);
    if ($control->containerId !== $ownedIds[0]) { throw new RuntimeException('Reconciliation changed immutable ID.'); }
    $execution = $creator->create($recipes['execution']);
    $ownedIds[] = $execution->containerId;
    foreach ([$control, $execution] as $binding) {
        if (trim($command->execute(['container', 'inspect', '--format', '{{.State.Status}} {{.State.Pid}}', $binding->containerId])) !== 'created 0') {
            throw new RuntimeException('Create/reconciliation started a unit automatically.');
        }
        if (!$start($binding, $recipes[$binding->unit->value])) { throw new RuntimeException('Registered unit start denied.'); }
        $startedPid = trim($command->execute(['container', 'inspect', '--format', '{{.State.Pid}}', $binding->containerId]));
        try {
            if ($start($binding, $recipes[$binding->unit->value])) { throw new LogicException('Repeated start admitted.'); }
        } catch (RuntimeException) {
        }
        if (trim($command->execute(['container', 'inspect', '--format', '{{.State.Pid}}', $binding->containerId])) !== $startedPid) {
            throw new RuntimeException('Repeated start changed the unit process identity.');
        }
    }
    $observed = trim($command->execute(['container', 'exec', $control->containerId, '/usr/local/bin/php', '-r',
        'echo hash("sha256", json_encode(array_map(getenv(...), ["APP_DEBUG", "APP_ENV", "APP_SECRET", "DATABASE_URL"]), JSON_THROW_ON_ERROR));']));
    if (!hash_equals(hash('sha256', json_encode(array_values($runtime->variables), JSON_THROW_ON_ERROR)), $observed)) {
        throw new RuntimeException('Runtime configuration was not preserved literally.');
    }
    $lease = $leases->acquire($namespace, $boot, 60);
    if ($lease === null || $lease->epoch !== 1) { throw new RuntimeException('Paired owner lease acquisition failed.'); }
    $connection->executeStatement("UPDATE worker_deployment_leases SET expires_at = clock_timestamp() - INTERVAL '1 second' WHERE namespace = :namespace", ['namespace' => $namespace]);
    $replacementBoot = bin2hex(random_bytes(16));
    if ($leases->acquire($namespace, $replacementBoot, 60) !== null) { throw new RuntimeException('Expiry released paired ownership.'); }
    try {
        (new DockerWorkerContainment($command->execute(...), $recipes['control']))->retire($control->containerId,
            new \App\Shared\Infrastructure\Worker\DeploymentLease($namespace, $replacementBoot, 1), DeploymentUnit::Control);
        throw new LogicException('Wrong owner retired a live unit.');
    } catch (RuntimeException) {
    }
    echo "PASS: paired committed creation/start claims, lost create reply, exact reconciliation, repeat-start rejection, literal environment and expiry fencing\n";
    $lostRemoval = new RuntimeException('Simulated lost successful removal reply.');
    try {
        (new RegisteredDeploymentRecovery($inventory, $retirements, static function (array $args) use ($command, $lostRemoval): string {
            $result = $command->execute($args);
            if (array_slice($args, 0, 3) === ['container', 'rm', '--force']) { throw $lostRemoval; }
            return $result;
        }, $recipes))->recover($namespace, $boot);
        throw new LogicException('Lost removal reply completed root retirement.');
    } catch (RuntimeException $error) {
        if ($error !== $lostRemoval) { throw $error; }
    }
    $pending = (new DoctrineDeploymentInventory($observer))->findBoot($namespace, $boot);
    if ($pending === null || $pending->retired || $pending->execution->retirement?->completed !== false
        || trim($command->execute(['container', 'inspect', '--format', '{{.State.Running}}', $control->containerId])) !== 'true'
        || $leases->acquire($namespace, $replacementBoot, 60) !== null) {
        throw new RuntimeException('Partial unit retirement released root ownership or stopped control prematurely.');
    }
    $recovery = new RegisteredDeploymentRecovery($inventory, $retirements, $command->execute(...), $recipes);
    if (!$recovery->recover($namespace, $boot) || !$recovery->recover($namespace, $boot)) {
        throw new RuntimeException('Durable per-unit retirement could not resume idempotently.');
    }
    $replacementRecipes = $recipesFor($replacementBoot);
    $reserve($replacementBoot, $replacementRecipes);
    foreach ($replacementRecipes as $recipe) { $binding = $creator->create($recipe); $ownedIds[] = $binding->containerId; }
    $replacementControl = $inventory->findUnit($namespace, $replacementBoot, DeploymentUnit::Control);
    if ($replacementControl === null || !$start($replacementControl, $replacementRecipes['control'])) { throw new RuntimeException('Replacement control start failed.'); }
    $replacement = $leases->acquire($namespace, $replacementBoot, 60);
    if ($replacement === null || $replacement->epoch !== 2 || $leases->renew($lease, 60)) {
        throw new RuntimeException('Joint containment did not advance epoch or fence stale lease operations.');
    }
    if (!$retirements->completeBootRetirement($namespace, $boot)
        || !(new RegisteredDeploymentRecovery($inventory, $retirements, $command->execute(...), $recipes))->recover($namespace, $boot)
        || (new DoctrineDeploymentLease($observer))->findForContainment($namespace) != $replacement) {
        throw new RuntimeException('Delayed old-boot retirement changed active successor ownership or epoch.');
    }
    foreach ([$control, $execution] as $binding) {
        try { $command->execute(['container', 'start', $binding->containerId]); throw new LogicException('Removed unit restarted.'); } catch (RuntimeException) {}
    }
    if (!(new RegisteredDeploymentRecovery($inventory, $retirements, $command->execute(...), $replacementRecipes))->recover($namespace, $replacementBoot)) {
        throw new RuntimeException('Replacement with never-started execution could not retire.');
    }
    // A boot with no create claims can retire without inventing physical proof.
    $uncreatedBoot = bin2hex(random_bytes(16));
    $uncreatedRecipes = $recipesFor($uncreatedBoot);
    $reserve($uncreatedBoot, $uncreatedRecipes);
    if (!(new RegisteredDeploymentRecovery($inventory, $retirements, $command->execute(...), $uncreatedRecipes))->recover($namespace, $uncreatedBoot)
        || $leases->acquire($namespace, $uncreatedBoot, 60) !== null) {
        throw new RuntimeException('Never-created pair retirement failed or allowed reuse.');
    }
    echo "PASS: interrupted execution removal preserves control/root; joint immutable-ID containment advances epoch; old IDs/tokens stay fenced; never-started and never-created units retire\n";
} finally {
    foreach ($ownedIds as $ownedId) {
        try { $command->execute(['container', 'rm', '--force', $ownedId]); } catch (RuntimeException) {}
    }
    try { $command->execute(['volume', 'rm', $ipcVolume]); } catch (RuntimeException) {}
    $observer->close();
    $connection->executeStatement('SET search_path TO public');
    $connection->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
    $connection->close();
}
