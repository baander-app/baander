#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\DevQualification\Fixtures\Runtime;
use Baander\DevQualification\Fixtures\Web\ShutdownWorkflow;
use Baander\Process\Runtime\Requirements;

try {
    Requirements::check();
    new ShutdownWorkflow(dirname(__DIR__))->run();
} catch (Throwable $failure) {
    fwrite(STDERR, Runtime::failure($failure) . "\n");
    exit(Runtime::status($failure));
}
