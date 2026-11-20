#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\DevEnvironment\Cli;

require __DIR__ . '/bootstrap.php';

try {
    Baander\Process\Runtime\Requirements::check();
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

exit(Cli::main(array_slice($_SERVER['argv'], 1)));
