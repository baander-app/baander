#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\Process\Arguments\Words;
use Baander\Process\Execution\CommandFailure;
use Baander\Process\Execution\CompletionPolicy;
use Baander\Process\Execution\EngineExecutor;
use Baander\Process\Execution\ExecutionContext;
use Baander\Process\Execution\ProcessSpec;
use Baander\Process\Runtime\Requirements;

require __DIR__ . '/bootstrap.php';

try {
    Requirements::check();
    $arguments = Words::split(getenv('cmd') ?: '');
    if ($arguments === []) {
        throw new RuntimeException('The command environment variable is empty or missing.');
    }
    $executor = new EngineExecutor(new ExecutionContext(getenv()));
    try {
        $result = $executor->run(new ProcessSpec(argv: $arguments, passthrough: true), new CompletionPolicy(null));
    } finally {
        $executor->close();
    }
    exit($result->exitCode);
} catch (CommandFailure $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit($error->getCode());
} catch (RuntimeException|InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
