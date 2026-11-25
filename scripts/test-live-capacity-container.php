#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\DevEnvironment\Host\ProcessEnvironment;
use Baander\DevQualification\Fixtures\Runtime;
use Baander\DevQualification\Fixtures\Web\CapacityWorkflow;
use Baander\Process\Runtime\Requirements;

require __DIR__ . '/bootstrap.php';

try {
    Requirements::check();
    $frontend = ProcessEnvironment::executable('corepack')
        ?? throw new RuntimeException('Corepack is required to run the existing frontend browser runner.');
    exit(CapacityWorkflow::main(dirname(__DIR__), $frontend));
} catch (Throwable $error) {
    fwrite(STDERR, Runtime::failure($error) . "\n");
    exit(Runtime::status($error));
}
