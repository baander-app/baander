#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\Tooling\Development\Acceptance\BrowserCheckpoint;
use Baander\Tooling\Runtime\Requirements;

try {
    Requirements::check();
    chdir(dirname(__DIR__));
    $arguments = $argv ?? [];
    if (count($arguments) !== 4) {
        throw new RuntimeException('Usage: test-two-command-browser-checkpoint.php PHASE ARTIFACTS BASE_URL');
    }
    exit(BrowserCheckpoint::run($arguments[1], $arguments[2], $arguments[3]));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
