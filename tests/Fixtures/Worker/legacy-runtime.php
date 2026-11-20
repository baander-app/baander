#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/scripts/bootstrap.php';
try {
    Baander\Process\Runtime\Requirements::check();
    exit(Baander\DevQualification\Fixtures\Worker\LegacySupervisor::main(
        array_slice($_SERVER['argv'], 1),
        dirname(
            __DIR__,
            3,
        ),
    ));
} catch (Throwable $error) {
    fwrite(STDERR, Baander\DevQualification\Fixtures\Runtime::failure($error) . "\n");
    exit(Baander\DevQualification\Fixtures\Runtime::status($error));
}
