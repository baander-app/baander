#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\DevQualification\Fixtures\Runtime;
use Baander\DevQualification\Fixtures\Web\CapacityWorkflow;
use Baander\Process\Runtime\Requirements;

require __DIR__ . '/bootstrap.php';

try {
    Requirements::check();
    $arguments = $_SERVER['argv'];
    if (count($arguments) !== 2) {
        throw new InvalidArgumentException('Usage: php scripts/test-live-capacity-exec.php MANIFEST');
    }
    exit(CapacityWorkflow::executeFixture(dirname(__DIR__), $arguments[1]));
} catch (Throwable $error) {
    fwrite(STDERR, Runtime::failure($error) . "\n");
    exit(Runtime::status($error));
}
