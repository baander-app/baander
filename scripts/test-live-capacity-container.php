#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\DevEnvironment\Host\ProcessEnvironment;

require __DIR__ . '/bootstrap.php';

try {
    Baander\Process\Runtime\Requirements::check();
    $frontend = ProcessEnvironment::executable('corepack')
        ?? throw new RuntimeException('Corepack is required to run the existing frontend browser runner.');
    exit(Baander\DevQualification\Fixtures\Web\CapacityWorkflow::main(dirname(__DIR__), $frontend));
} catch (Throwable $error) {
    fwrite(STDERR, Baander\DevQualification\Fixtures\Runtime::failure($error) . "\n");
    exit(Baander\DevQualification\Fixtures\Runtime::status($error));
}
