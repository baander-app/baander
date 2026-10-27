#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/relay/tests/bootstrap.php';

use Baander\Tooling\Registry\QualificationRunner;

try {
    exit(new QualificationRunner(dirname(__DIR__))->run(array_slice($_SERVER['argv'], 1)));
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit($failure->getCode() > 0 && $failure->getCode() <= 255 ? $failure->getCode() : 1);
}
