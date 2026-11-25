#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Infrastructure\Worker\NativeExecutionDaemon;
use Baander\Core\EnvironmentVariable;

// A fresh supervised execution process; no kernel or database credentials are needed.
require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $profile = DeploymentProfile::fromEnvironment();
    $daemon = new NativeExecutionDaemon(
        getenv(EnvironmentVariable::BAANDER_WORKER_NAMESPACE) ?: '',
        getenv(EnvironmentVariable::BAANDER_WORKER_BOOT_ID) ?: '',
        (int) getenv(EnvironmentVariable::BAANDER_WORKER_LEASE_EPOCH),
        $profile,
    );
    exit($daemon->run(getenv(EnvironmentVariable::BAANDER_NATIVE_SOCKET) ?: ''));
} catch (Throwable) {
    fwrite(STDERR, "Native execution launcher failed.\n");
    exit(1);
}
