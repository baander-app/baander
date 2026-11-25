#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\DevCi\Backend\StackIsolation;
use Baander\Process\Runtime\Requirements;

require __DIR__ . '/bootstrap.php';
try {
    Requirements::check();
    exit(StackIsolation::main(array_slice($_SERVER['argv'], 1), dirname(__DIR__)));
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
