#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    Baander\Process\Runtime\Requirements::check();
    exit(Baander\DevQualification\Fixtures\Messaging\Qualification::main(dirname(__DIR__), 'producer'));
} catch (Throwable $error) {
    fwrite(STDERR, Baander\DevQualification\Fixtures\Runtime::failure($error) . "\n");
    exit(Baander\DevQualification\Fixtures\Runtime::status($error));
}
