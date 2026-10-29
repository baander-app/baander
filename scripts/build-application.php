#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
try {
    Baander\Tooling\Runtime\Requirements::check();
    exit(Baander\Tooling\Build\Command::main(array_slice($_SERVER['argv'], 1), dirname(__DIR__), getenv()));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit($error->getCode() > 0 && $error->getCode() <= 255 ? $error->getCode() : 1);
}
