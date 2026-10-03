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
    $recipe = $manifest->recipe($credentials->runtimeEnvironment);
    $connection = \Doctrine\DBAL\DriverManager::getConnection($credentials->connectionParameters());
    $inventory = new \App\Shared\Infrastructure\Worker\DoctrineDeploymentInventory($connection);
    $retirements = new \App\Shared\Infrastructure\Worker\DoctrineDeploymentRetirement($connection);
    $docker = new \App\Shared\Infrastructure\Worker\DockerWorkerCommand($manifest->dockerBinary, $manifest->dockerEndpoint);
    $creator = new \App\Shared\Infrastructure\Worker\DockerDeploymentCreate($inventory, $docker->execute(...));
    $configured = true;
    $binding = null;
    $result = [];
    $success = true;
    switch ($action) {
        case 'create':
            if ($inventory->find($recipe->namespace, $recipe->bootId) !== null
                || $inventory->matchesCreate($recipe)) {
                $success = false;
                $result = ['state' => 'creation_already_attempted'];
                break;
            }
            $binding = $creator->create($recipe);
            $result = ['state' => 'created'];
            break;
        case 'reconcile-create':
            $binding = $creator->reconcile($recipe);
            $result = ['state' => 'created'];
            break;
        case 'start':
            $binding = $inventory->find($recipe->namespace, $recipe->bootId);
            if ($binding === null) {
                $success = false;
                $result = ['state' => 'unregistered'];
                break;
            }
            if ($inventory->hasStartClaim($binding) || $retirements->find($recipe->namespace, $recipe->bootId) !== null) {
                $success = false;
                $result = ['state' => 'start_denied', 'readiness' => 'not_checked'];
                break;
            }
            // Reconciliation checks the full committed recipe and never creates.
            // A running/exited container fails here; start claims are not retries.
            $checked = $creator->reconcile($recipe);
            if ($binding->namespace !== $checked->namespace || $binding->bootId !== $checked->bootId
                || $binding->daemonId !== $checked->daemonId || $binding->containerId !== $checked->containerId) {
                throw new RuntimeException('Immutable binding changed.');
            }
            $success = (new \App\Shared\Infrastructure\Worker\RegisteredDeploymentStart($inventory, $docker->execute(...)))->start($binding);
            $result = ['state' => $success ? 'start_confirmed' : 'start_denied', 'readiness' => 'not_checked'];
            break;
        case 'recover':
            $binding = $inventory->find($recipe->namespace, $recipe->bootId);
            if ($binding !== null && $binding->daemonId !== $recipe->daemonId) {
                throw new RuntimeException('Manifest and inventory daemon differ.');
            }
            $success = (new \App\Shared\Infrastructure\Worker\RegisteredDeploymentRecovery($inventory, $retirements, $docker->execute(...)))
                ->recover($recipe->namespace, $recipe->bootId);
            $result = ['state' => $success ? 'retired' : 'unregistered'];
            break;
        case 'status':
            // Separate committed observations, not an atomic snapshot or a grant.
            $binding = $inventory->find($recipe->namespace, $recipe->bootId);
            $retirement = $retirements->find($recipe->namespace, $recipe->bootId);
            $lease = (new \App\Shared\Infrastructure\Worker\DoctrineDeploymentLease($connection))->findForContainment($recipe->namespace);
            $result = [
                'creationIntentMatches' => $inventory->matchesCreate($recipe),
                'registered' => $binding !== null,
                'startClaimed' => $binding !== null && $inventory->hasStartClaim($binding),
                'retirement' => $retirement === null ? 'none' : ($retirement->completed ? 'completed' : 'pending'),
                'reservation' => $lease === null ? 'none' : ($lease->bootId === $recipe->bootId ? 'this_boot' : 'another_boot'),
                'readiness' => 'not_checked',
            ];
            break;
    }
    $response = ['success' => $success, 'action' => $action, 'result' => [
        'namespace' => $recipe->namespace, 'bootId' => $recipe->bootId,
        'containerId' => $binding?->containerId, ...$result,
    ]];
    $exitCode = $success ? 0 : 3;
} catch (Throwable) {
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
