#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\DevQualification\Fixtures\DockerContext;
use Baander\DevQualification\Fixtures\Runtime;
use Baander\Process\Runtime\Requirements;

require __DIR__ . '/bootstrap.php';
try {
    Requirements::check();
    exit(DockerContext::main(array_slice($_SERVER['argv'], 1), dirname(__DIR__)));
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, Runtime::failure($error) . "\n");
    exit(2);
} catch (Throwable $error) {
    fwrite(STDERR, Runtime::failure($error) . "\n");
    exit(Runtime::status($error));
}
