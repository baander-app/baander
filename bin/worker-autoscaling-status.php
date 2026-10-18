#!/usr/bin/env php
<?php

declare(strict_types=1);

// Private host status probe: no kernel boot, environment loading or network I/O.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('memory_limit', '64M');

try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $arguments = $_SERVER['argv'] ?? [];
    if (PHP_SAPI !== 'cli' || count($arguments) !== 5) {
        throw new RuntimeException('Status requires deployment identity and profile.');
    }
    [, $namespace, $bootId, $epoch, $profileJson] = $arguments;
    if (preg_match('/^[1-9][0-9]{0,18}$/D', $epoch) !== 1 || strlen($epoch) > strlen((string) PHP_INT_MAX)
        || (strlen($epoch) === strlen((string) PHP_INT_MAX) && strcmp($epoch, (string) PHP_INT_MAX) > 0)) {
        throw new RuntimeException('Status requires a bounded lease epoch.');
    }
    $lease = new \App\Shared\Infrastructure\Worker\DeploymentLease($namespace, $bootId, (int) $epoch);
    $profile = \App\Shared\Application\DTO\WorkerAutoscalingProfile::fromJson($profileJson);
    $status = new \App\Shared\Infrastructure\Worker\Autoscaling\AutoscalingStatus('/run/baander-worker', $lease, $profile);
    $json = json_encode($status->snapshot(hrtime(true) / 1e9), JSON_THROW_ON_ERROR);
    if (strlen($json) > 8192) {
        throw new RuntimeException('Status exceeds its output bound.');
    }
    fwrite(STDOUT, $json . "\n");
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Worker autoscaling status unavailable.\n");
    exit(1);
}
