#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\DevQualification\Fixtures\Runtime;
use Baander\DevQualification\Fixtures\Startup\Probe;
use Baander\Process\Runtime\Requirements;

require __DIR__ . '/bootstrap.php';

try {
    Requirements::check();
    exit(Probe::main(dirname(__DIR__)));
} catch (Throwable $error) {
    fwrite(STDERR, Runtime::failure($error) . "\n");
    exit(Runtime::status($error));
}
