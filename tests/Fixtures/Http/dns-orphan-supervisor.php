#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\DevQualification\Fixtures\Http\OrphanSupervisor;
use Baander\Process\Runtime\Requirements;

require dirname(__DIR__, 3) . '/scripts/bootstrap.php';

try {
    Requirements::check(extensions: ['FFI']);
    exit(OrphanSupervisor::main(array_slice($_SERVER['argv'], 1)));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
