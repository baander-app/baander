#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Infrastructure\Worker\Autoscaling\AutoscalingStatus;
use App\Shared\Infrastructure\Worker\DeploymentLease;

// Private host status probe: no kernel boot, environment loading or network I/O.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('memory_limit', '64M');

try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $arguments = $_SERVER['argv'] ?? [];
    if (PHP_SAPI !== 'cli' || count($arguments) !== 6) {
        throw new RuntimeException('Status requires deployment identity and profiles.');
    }
    [, $namespace, $bootId, $epoch, $profileJson, $deploymentProfileJson] = $arguments;
    if (preg_match('/^[1-9][0-9]{0,18}$/D', $epoch) !== 1 || strlen($epoch) > strlen((string) PHP_INT_MAX)
        || (strlen($epoch) === strlen((string) PHP_INT_MAX) && strcmp($epoch, (string) PHP_INT_MAX) > 0)) {
        throw new RuntimeException('Status requires a bounded lease epoch.');
    }
    $lease = new DeploymentLease($namespace, $bootId, (int) $epoch);
    $profile = WorkerAutoscalingProfile::fromJson($profileJson);
    $deploymentProfile = DeploymentProfile::fromJson($deploymentProfileJson);
    $status = new AutoscalingStatus('/run/baander-worker', $lease, $profile, $deploymentProfile);
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
