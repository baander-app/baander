#!/usr/bin/env php
<?php

declare(strict_types=1);

// Trusted host-only composition root. Never boot Symfony, load dotenv, or mount
// Docker authority into an application container. Bound this process externally;
// interruption or an unconfirmed reply never permits repeating create/start.
ini_set('display_errors', '0');
ini_set('log_errors', '0');

$action = null;
$connection = null;
$configured = false;
$operationOwnsBoot = false;
$exitCode = 1;
$response = ['success' => false, 'action' => null, 'error' => 'operation_unconfirmed'];
try {
    $arguments = $_SERVER['argv'] ?? [];
    if (PHP_SAPI !== 'cli' || !is_array($arguments)) {
        throw new InvalidArgumentException('CLI invocation required.');
    }
    if (count($arguments) === 2 && $arguments[1] === '--help') {
        echo "Usage: php bin/worker-deployment.php <create|reconcile-create|start|status|recover> /absolute/manifest.json /absolute/credentials.json\n";
        echo "Use an external deadline. Unconfirmed create/start must be reconciled, never retried automatically.\n";
        exit(0);
    }
    if (count($arguments) !== 4 || !is_string($arguments[1])
        || !in_array($arguments[1], ['create', 'reconcile-create', 'start', 'status', 'recover'], true)
        || !is_string($arguments[2]) || !str_starts_with($arguments[2], '/') || !is_file($arguments[2])
        || !is_string($arguments[3])) {
        throw new InvalidArgumentException('Invalid operator invocation.');
    }
    $action = $arguments[1];
    require dirname(__DIR__) . '/vendor/autoload.php';
    $json = @file_get_contents($arguments[2], false, null, 0, 8193);
    if ($json === false) {
        throw new InvalidArgumentException('Cannot read manifest.');
    }
    $manifest = \App\Shared\Infrastructure\Worker\DeploymentOperatorManifest::fromJson($json);
    $credentials = \App\Shared\Infrastructure\Worker\DeploymentOperatorCredentials::fromFile($arguments[3]);
    $recipes = [
        'control' => $manifest->recipe(\App\Shared\Application\DTO\DeploymentUnit::Control, $credentials->runtimeEnvironment),
        'execution' => $manifest->recipe(\App\Shared\Application\DTO\DeploymentUnit::Execution, $credentials->runtimeEnvironment),
    ];
    $manifestHash = $manifest->fingerprint($credentials->runtimeEnvironment);
    $connection = \App\Shared\Infrastructure\Doctrine\DedicatedConnectionFactory::fromParams($credentials->connectionParameters());
    $inventory = new \App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory($connection);
    $retirements = new \App\Shared\Infrastructure\Worker\DoctrineDeploymentRetirement($connection);
    $docker = new \App\Shared\Infrastructure\Worker\DockerWorkerCommand($manifest->dockerBinary, $manifest->dockerEndpoint);
    $creator = new \App\Shared\Infrastructure\Worker\DockerDeploymentCreate($inventory, $docker->execute(...));
    $configured = true;
    $success = true;
    $result = [];
    $boot = $inventory->findBoot($manifest->namespace, $manifest->bootId);
    switch ($action) {
        case 'create':
            if ($boot !== null) {
                $success = false;
                $result = ['state' => 'creation_already_attempted'];
                break;
            }
            $host = json_decode($docker->execute(['info', '--format', '{"id":{{json .ID}},"cpus":{{.NCPU}},"memory":{{.MemTotal}},"cgroupVersion":{{json .CgroupVersion}}}']), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($host) || ($host['id'] ?? null) !== $manifest->daemonId
                || ($host['cgroupVersion'] ?? null) !== '2' || !is_int($host['cpus'] ?? null) || !is_int($host['memory'] ?? null)
                || max([...$manifest->control->cpuSet, ...$manifest->execution->cpuSet]) >= $host['cpus']
                || $manifest->control->memoryBytes + $manifest->execution->memoryBytes + 1024 * 1024 * 1024 > $host['memory']) {
                throw new RuntimeException('Docker host cannot admit the declared worker resource pair.');
            }
            if (!$inventory->reserveBoot($manifest->namespace, $manifest->bootId, $manifestHash, $manifest->daemonId,
                $recipes['control']->fingerprint(), $recipes['execution']->fingerprint())) {
                $success = false;
                $result = ['state' => 'reservation_denied'];
                break;
            }
            $operationOwnsBoot = true;
            foreach ($recipes as $recipe) {
                $creator->create($recipe);
            }
            $result = ['state' => 'created'];
            break;
        case 'reconcile-create':
            foreach ($recipes as $recipe) {
                $creator->reconcile($recipe);
            }
            $result = ['state' => 'created'];
            break;
        case 'start':
            if ($boot === null || $boot->manifestHash !== $manifestHash || $boot->retirementRequested
                || $boot->control->binding === null || $boot->execution->binding === null
                || $boot->control->startClaimed || $boot->execution->startClaimed) {
                $success = false;
                $result = ['state' => 'start_denied', 'readiness' => 'not_checked'];
                break;
            }
            $operationOwnsBoot = true;
            foreach ([$boot->control, $boot->execution] as $state) {
                $recipe = $recipes[$state->unit->value];
                $binding = $creator->reconcile($recipe);
                if ($binding != $state->binding) {
                    throw new RuntimeException('Immutable unit binding changed.');
                }
            }
            foreach ([$boot->control, $boot->execution] as $state) {
                $recipe = $recipes[$state->unit->value];
                if ($state->binding === null || !(new \App\Shared\Infrastructure\Worker\RegisteredDeploymentStart($inventory, $docker->execute(...), $recipe))->start($state->binding)) {
                    $success = false;
                    break;
                }
            }
            $result = ['state' => $success ? 'start_confirmed' : 'start_denied', 'readiness' => 'not_checked'];
            if (!$success) {
                $retirements->requestRetirement($manifest->namespace, $manifest->bootId);
            }
            break;
        case 'recover':
            $success = (new \App\Shared\Infrastructure\Worker\RegisteredDeploymentRecovery($inventory, $retirements, $docker->execute(...), $recipes))
                ->recover($manifest->namespace, $manifest->bootId);
            $result = ['state' => $success ? 'retired' : 'unconfirmed'];
            break;
        case 'status':
            $lease = (new \App\Shared\Infrastructure\Worker\DoctrineDeploymentLease($connection))->findForContainment($manifest->namespace);
            $result = ['manifestMatches' => $boot?->manifestHash === $manifestHash,
                'retirement' => $boot === null ? 'unregistered' : ($boot->retired ? 'completed' : ($boot->retirementRequested ? 'pending' : 'none')),
                'reservation' => $lease === null ? 'none' : ($lease->bootId === $manifest->bootId ? 'this_boot' : 'another_boot'),
                'readiness' => 'not_checked'];
            break;
    }
    $boot = $inventory->findBoot($manifest->namespace, $manifest->bootId);
    $units = [];
    foreach ([$boot?->control, $boot?->execution] as $state) {
        if ($state !== null) {
            $units[$state->unit->value] = ['containerId' => $state->binding?->containerId,
                'createClaimed' => $state->createClaimed, 'startClaimed' => $state->startClaimed,
                'cancelled' => $state->cancelled, 'removed' => $state->retirement?->completed === true];
        }
    }
    $response = ['success' => $success, 'action' => $action, 'result' => [
        'namespace' => $manifest->namespace, 'bootId' => $manifest->bootId, 'units' => $units, ...$result,
    ]];
    $exitCode = $success ? 0 : 3;
} catch (Throwable) {
    if ($operationOwnsBoot && isset($retirements, $manifest)) {
        try {
            $retirements->requestRetirement($manifest->namespace, $manifest->bootId);
        } catch (Throwable) {
            // Unknown journal outcomes remain reserved; recovery must reconcile.
        }
    }
    // Driver, JSON, Docker and filesystem diagnostics can contain credentials.
    $response = ['success' => false, 'action' => $action,
        'error' => $configured ? 'operation_unconfirmed' : 'invalid_configuration'];
    $exitCode = $configured ? 1 : 2;
} finally {
    if ($connection !== null) {
        try {
            $connection->close();
        } catch (Throwable) {
        }
    }
}
echo json_encode($response, JSON_THROW_ON_ERROR), "\n";
exit($exitCode);
