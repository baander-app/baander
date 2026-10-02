#!/usr/bin/env php
<?php

declare(strict_types=1);

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
    $request = \App\Shared\Infrastructure\Worker\LeaseAgentProcess::validateRequest($decoded);
    $result['action'] = $request['action'];
    $result['sequence'] = $request['sequence'];
} catch (Throwable) {
    echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
try {
    $url = getenv('DATABASE_URL');
    if (!$url) {
        throw new RuntimeException('Database not configured.');
    }
    $params = (new \Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
    if (($params['driver'] ?? null) !== 'pdo_pgsql') {
        throw new RuntimeException('Unsupported database.');
    }
    $repository = new \App\Shared\Infrastructure\Worker\DoctrineDeploymentLease(\Doctrine\DBAL\DriverManager::getConnection($params));
    $lease = $request['action'] === 'acquire'
        ? $repository->acquire($request['namespace'], $request['bootId'], $request['ttlSeconds'])
        : new \App\Shared\Infrastructure\Worker\DeploymentLease($request['namespace'], $request['bootId'], $request['epoch']);
    $success = $lease !== null && ($request['action'] === 'acquire' || $repository->renew($lease, $request['ttlSeconds']));
    $result['success'] = $success;
    $result['category'] = $success ? ($request['action'] === 'acquire' ? 'acquired' : 'renewed') : 'denied';
    $result['lease'] = $success ? ['namespace' => $lease->namespace, 'bootId' => $lease->bootId, 'epoch' => $lease->epoch] : null;
} catch (Throwable) {
    // Includes possibly committed-but-unacknowledged operations: never grant admission.
    $result['category'] = 'database_error';
}
echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
