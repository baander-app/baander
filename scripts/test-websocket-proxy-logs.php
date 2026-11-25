#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\DevQualification\Fixtures\Http\ProxyQualification;
use Baander\DevQualification\Fixtures\Runtime;
use Baander\Process\Runtime\Requirements;

require __DIR__ . '/bootstrap.php';

try {
    Requirements::check();
    exit(ProxyQualification::main(array_slice($_SERVER['argv'], 1), dirname(__DIR__)));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(Runtime::status($error));
}
