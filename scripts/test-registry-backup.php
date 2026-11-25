#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\DevRegistry\ContainerQualification;

require __DIR__ . '/bootstrap.php';

try {
    exit(new ContainerQualification(dirname(__DIR__))->run(true));
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit($failure->getCode() > 0 && $failure->getCode() <= 255 ? $failure->getCode() : 1);
}
