#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\Core\EnvironmentVariable;

// A fresh supervised execution process; no kernel or database credentials are needed.
require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $profile = \App\Shared\Application\DTO\DeploymentProfile::fromEnvironment();
    $daemon = new \App\Shared\Infrastructure\Worker\NativeExecutionDaemon(
        getenv(EnvironmentVariable::BAANDER_WORKER_NAMESPACE) ?: '',
        getenv(EnvironmentVariable::BAANDER_WORKER_BOOT_ID) ?: '',
        (int) getenv(EnvironmentVariable::BAANDER_WORKER_LEASE_EPOCH),
        $profile,
    );
    exit($daemon->run(getenv(EnvironmentVariable::BAANDER_NATIVE_SOCKET) ?: ''));
} catch (\Throwable) {
    fwrite(STDERR, "Native execution launcher failed.\n");
    exit(1);
}
