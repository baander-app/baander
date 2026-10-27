#!/usr/bin/env php
<?php

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

try {
    exit(new Baander\Tooling\Registry\ContainerQualification(dirname(__DIR__))->run(false));
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit($failure->getCode() > 0 && $failure->getCode() <= 255 ? $failure->getCode() : 1);
}
