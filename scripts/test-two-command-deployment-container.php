#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\Tooling\Development\Acceptance\Support;
use Baander\Tooling\Runtime\Requirements;

try {
    Requirements::check();
    $environment = getenv();
    $configuration = \Baander\Tooling\Configuration\ToolingConfiguration::forRepository(dirname(__DIR__), $environment);
    $executor = new \Baander\Tooling\Process\EngineExecutor(\Baander\Tooling\Process\ExecutionContext::bounded($configuration->budgets->seconds('fixture'), $environment));
    chdir(dirname(__DIR__));
    Support::check((getenv('BAANDER_TEST_IMAGE') ?: '') !== '', 'BAANDER_TEST_IMAGE: Set the production worker target built from this checkout.');
    if (!in_array(getenv('BAANDER_TEST_BROWSER_ENGINE') ?: 'chromium', ['chromium', 'firefox'], true)) {
        fwrite(STDERR, "BAANDER_TEST_BROWSER_ENGINE must be chromium or firefox.\n");
        exit(64);
    }
    $result = $executor->run(new \Baander\Tooling\Process\ProcessSpec(['env', 'BAANDER_TEST_TWO_COMMAND=1', 'BAANDER_WORKER_PRODUCTION_TIMEOUT_ACTIVE=1',
        'BAANDER_WORKER_OPERATOR_TIMEOUT_ACTIVE=1', 'bash', 'scripts/test-worker-production-container.sh'], timeout: null, passthrough: true, processGroup: true), new \Baander\Tooling\Process\CompletionPolicy(null));
    exit($result->exitCode);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit($error->getCode() > 0 && $error->getCode() < 256 ? $error->getCode() : 1);
}
