#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\DevEnvironment\Cli;
use Baander\Process\Runtime\Requirements;

require __DIR__ . '/bootstrap.php';

try {
    Requirements::check();
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

exit(Cli::main(array_slice($_SERVER['argv'], 1)));
