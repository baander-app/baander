#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\DevQualification\Fixtures\Runtime;
use Baander\DevQualification\Fixtures\Worker\PostgresSampler;
use Baander\Process\Runtime\Requirements;

require dirname(__DIR__, 3) . '/scripts/bootstrap.php';
try {
    Requirements::check();
    exit(PostgresSampler::main(
        array_slice($_SERVER['argv'], 1),
        dirname(
            __DIR__,
            3,
        ),
    ));
} catch (Throwable $error) {
    fwrite(STDERR, Runtime::failure($error) . "\n");
    exit(Runtime::status($error));
}
