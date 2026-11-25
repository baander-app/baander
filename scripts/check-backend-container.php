#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\DevCi\Backend\StaticCheck;
use Baander\Process\Runtime\Requirements;

require __DIR__ . '/bootstrap.php';

try {
    Requirements::check();
    exit(StaticCheck::main(array_slice($_SERVER['argv'], 1), dirname(__DIR__)));
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit($error->getCode() > 0 && $error->getCode() <= 255 ? $error->getCode() : 1);
}
