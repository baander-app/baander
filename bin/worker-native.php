#!/usr/bin/env php
<?php

declare(strict_types=1);

// A fresh supervised execution process; no kernel or database credentials are needed.
require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $daemon = new \App\Shared\Infrastructure\Worker\NativeExecutionDaemon(
        getenv('BAANDER_WORKER_NAMESPACE') ?: '',
        getenv('BAANDER_WORKER_BOOT_ID') ?: '',
        (int) getenv('BAANDER_WORKER_LEASE_EPOCH'),
    );
    exit($daemon->run(getenv('BAANDER_NATIVE_SOCKET') ?: ''));
} catch (\Throwable) {
    fwrite(STDERR, "Native execution launcher failed.\n");
    exit(1);
}
