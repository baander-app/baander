#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Doctrine\DedicatedConnectionFactory;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\DoctrineDeploymentLease;
use App\Shared\Infrastructure\Worker\LeaseAgentProcess;
use Baander\Core\EnvironmentVariable;
use Doctrine\DBAL\Tools\DsnParser;

// Fresh CLI only: no kernel, environment-file loading, handlers or containment acknowledgment.
ini_set('display_errors', '0');
ini_set('log_errors', '0');

$request = null;
$result = ['action' => null, 'sequence' => null, 'success' => false, 'category' => 'invalid_request', 'lease' => null];
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $arguments = $_SERVER['argv'] ?? [];
    if (!is_array($arguments) || count($arguments) !== 2 || !isset($arguments[1]) || !is_string($arguments[1]) || strlen($arguments[1]) > 8192 || ($json = base64_decode($arguments[1], true)) === false) {
        throw new InvalidArgumentException('Invalid request.');
    }
    $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Invalid request.');
    }
    $request = LeaseAgentProcess::validateRequest($decoded);
    $result['action'] = $request['action'];
    $result['sequence'] = $request['sequence'];
} catch (Throwable) {
    echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
try {
    $url = getenv(EnvironmentVariable::DATABASE_CONTROL_URL);
    if (!$url) {
        throw new RuntimeException('Database not configured.');
    }
    $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
    if (($params['driver'] ?? null) !== 'pdo_pgsql') {
        throw new RuntimeException('Unsupported database.');
    }
    $connection = DedicatedConnectionFactory::fromParams($params);
    $repository = new DoctrineDeploymentLease($connection);
    if ($request['action'] === 'observe') {
        $lease = $repository->observe($request['namespace'], $request['bootId'], $request['ttlSeconds']);
        $success = $lease !== null && ($request['epoch'] === null || $lease->epoch === $request['epoch']);
    } else {
        $lease = $request['action'] === 'acquire'
            ? $repository->acquire($request['namespace'], $request['bootId'], $request['ttlSeconds'])
            : new DeploymentLease($request['namespace'], $request['bootId'], $request['epoch']);
        $success = $lease !== null && ($request['action'] === 'acquire' || $repository->renew($lease, $request['ttlSeconds']));
    }
    $result['success'] = $success;
    $result['category'] = $success ? match ($request['action']) { 'acquire' => 'acquired', 'observe' => 'observed', default => 'renewed' } : 'denied';
    $result['lease'] = $success ? ['namespace' => $lease->namespace, 'bootId' => $lease->bootId, 'epoch' => $lease->epoch] : null;
} catch (Throwable) {
    // Includes possibly committed-but-unacknowledged operations: never grant admission.
    $result['category'] = 'database_error';
}
echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
