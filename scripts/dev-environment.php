#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    Baander\Tooling\Runtime\Requirements::check();
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

exit(Baander\Tooling\Development\Cli::main(array_slice($_SERVER['argv'], 1)));
