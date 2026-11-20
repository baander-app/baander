#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\DevWebsite\Command;

require __DIR__ . '/bootstrap.php';
try {
    Baander\Process\Runtime\Requirements::check(swoole: false, extensions: ['dom', 'mbstring', 'tokenizer']);
    exit(Command::main(array_slice($_SERVER['argv'], 1), dirname(__DIR__), getenv()));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit($error->getCode() > 0 && $error->getCode() <= 255 ? $error->getCode() : 1);
}
