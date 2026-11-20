#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    Baander\Process\Runtime\Requirements::check();
    $arguments = $_SERVER['argv'];
    if (count($arguments) !== 2) {
        throw new InvalidArgumentException('Usage: php scripts/test-live-capacity-exec.php MANIFEST');
    }
    exit(Baander\DevQualification\Fixtures\Web\CapacityWorkflow::executeFixture(dirname(__DIR__), $arguments[1]));
} catch (Throwable $error) {
    fwrite(STDERR, Baander\DevQualification\Fixtures\Runtime::failure($error) . "\n");
    exit(Baander\DevQualification\Fixtures\Runtime::status($error));
}
