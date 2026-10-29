#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Development\Acceptance\BrowserCheckpoint;
use Baander\Tooling\Docker\{Client, Target, TargetResolver};
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Process\{CommandFailure, EngineExecutor, ExecutionContext};
use Baander\Tooling\Runtime\Requirements;

$executor = null;
try {
    Requirements::check();
    $repository = dirname(__DIR__);
    chdir($repository);
    $arguments = $argv ?? [];
    if (count($arguments) !== 4) {
        throw new RuntimeException('Usage: test-two-command-browser-checkpoint.php PHASE ARTIFACTS BASE_URL');
    }
    $environment = getenv();
    [$script, $phase, $artifacts, $baseUrl] = $arguments;
    if ($phase === 'cleanup') {
        exit(BrowserCheckpoint::cleanup($artifacts, $environment));
    }
    $configuration = ToolingConfiguration::forRepository(dirname(__DIR__), $environment);
    $executor = new EngineExecutor(ExecutionContext::bounded($configuration->budgets->seconds('browser'), $environment, $repository));
    if (is_file($artifacts . '/browser-container')) {
        $target = Target::fromArray(AtomicFile::readJson($artifacts . '/browser-container')['target']);
    } else {
        $target = new TargetResolver($executor, $environment)->resolve($configuration->docker, $artifacts . '/browser-transport');
    }
    $status = new BrowserCheckpoint(new Client($executor, $target, $environment), $configuration, $environment, $repository)->run($phase, $artifacts, $baseUrl);
    $executor->close();
    exit($status);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    $executor?->close();
    exit($error instanceof CommandFailure ? $error->getCode() : 1);
}
