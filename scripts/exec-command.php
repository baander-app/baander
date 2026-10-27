#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\Tooling\CommandLine\Words;
use Baander\Tooling\Process\ProcessRunner;
use Baander\Tooling\Process\ProcessSpec;
use Baander\Tooling\Runtime\Requirements;

require __DIR__ . '/bootstrap.php';

try {
    Requirements::check();
    $arguments = Words::split(getenv('cmd') ?: '');
    if ($arguments === []) {
        throw new RuntimeException('The command environment variable is empty or missing.');
    }
    exit(new ProcessRunner()->run(new ProcessSpec(argv: $arguments, environment: getenv(), passthrough: true))->exitCode);
} catch (RuntimeException|InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
