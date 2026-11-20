#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    Baander\Process\Runtime\Requirements::check();
    exit(Baander\DevQualification\Fixtures\Http\ProxyQualification::main(array_slice($_SERVER['argv'], 1), dirname(__DIR__)));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(Baander\DevQualification\Fixtures\Runtime::status($error));
}
