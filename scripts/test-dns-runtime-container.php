#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    Baander\Process\Runtime\Requirements::check();
    exit(Baander\DevQualification\Fixtures\Http\DnsQualification::main(dirname(__DIR__)));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
