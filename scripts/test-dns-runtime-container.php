#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    Baander\Tooling\Runtime\Requirements::check();
    exit(Baander\Tooling\Fixtures\Http\DnsQualification::main(dirname(__DIR__)));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
