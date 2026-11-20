#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
try {
    Baander\Process\Runtime\Requirements::check();
    exit(Baander\DevQualification\Fixtures\Worker\Recovery::main(array_slice($_SERVER['argv'], 1), dirname(__DIR__)));
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, Baander\DevQualification\Fixtures\Runtime::failure($error) . "\n");
    exit(2);
} catch (Throwable $error) {
    fwrite(STDERR, Baander\DevQualification\Fixtures\Runtime::failure($error) . "\n");
    exit(Baander\DevQualification\Fixtures\Runtime::status($error));
}
