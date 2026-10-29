#!/usr/bin/env php
<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Inventory\Worker;
use Baander\Tooling\Process\CompletionPolicy;
use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;
use Baander\Tooling\Runtime\Requirements;

try {
    Requirements::check();
    $environment = getenv();
    // Recovery's child owns the saved cleanup budget and must ignore current configuration.
    $context = ($_SERVER['argv'][1] ?? '') === 'cleanup-collection'
        ? new ExecutionContext($environment, ignoreCancellation: true)
        : ExecutionContext::bounded(ToolingConfiguration::resolve(environment: $environment)->budgets->seconds('backend'), $environment);
    $executor = new EngineExecutor($context);
    try {
        $result = $executor->run(Worker::command(__DIR__ . '/vendor/autoload.php', array_slice($_SERVER['argv'], 1), $environment), new CompletionPolicy(null));
        fwrite(STDOUT, $result->stdout);
        fwrite(STDERR, $result->stderr);
    } finally {
        $executor->close();
    }
    exit($result->exitCode);
} catch (Throwable $error) {
    fwrite(STDERR, 'Application inventory failed: ' . $error->getMessage() . "\n");
    exit($error->getCode() > 0 && $error->getCode() <= 255 ? $error->getCode() : 1);
}
