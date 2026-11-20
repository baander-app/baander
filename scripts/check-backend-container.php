#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    Baander\Process\Runtime\Requirements::check();
    exit(Baander\DevCi\Backend\StaticCheck::main(array_slice($_SERVER['argv'], 1), dirname(__DIR__)));
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit($error->getCode() > 0 && $error->getCode() <= 255 ? $error->getCode() : 1);
}
