#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\Tooling\Development\Acceptance\Support;
use Baander\Tooling\Runtime\Requirements;

try {
    Requirements::check();
    chdir(dirname(__DIR__));
    Support::check((getenv('BAANDER_TEST_IMAGE') ?: '') !== '', 'BAANDER_TEST_IMAGE: Set the production worker target built from this checkout.');
    if (!in_array(getenv('BAANDER_TEST_BROWSER_ENGINE') ?: 'chromium', ['chromium', 'firefox'], true)) {
        fwrite(STDERR, "BAANDER_TEST_BROWSER_ENGINE must be chromium or firefox.\n");
        exit(64);
    }
    $result = Support::run(['timeout', '600s', 'env', 'BAANDER_TEST_TWO_COMMAND=1', 'BAANDER_WORKER_PRODUCTION_TIMEOUT_ACTIVE=1',
        'BAANDER_WORKER_OPERATOR_TIMEOUT_ACTIVE=1', 'bash', 'scripts/test-worker-production-container.sh'], timeout: null, check: false, passthrough: true);
    exit($result->exitCode);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
