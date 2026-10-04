<?php

declare(strict_types=1);

$argv = $_SERVER['argv'] ?? [];

// External controller test harness; never copied into an application entrypoint.
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\DockerWorkerCommand;
use App\Shared\Infrastructure\Worker\DockerWorkerContainment;

$command = new DockerWorkerCommand($argv[1], $argv[2]);
$containment = new DockerWorkerContainment($command->execute(...));
$containment->retire($argv[3], new DeploymentLease('baander.app:retirement-test', $argv[4], 1));
echo "deployment_retired\n";
