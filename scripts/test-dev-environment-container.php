#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\Tooling\Development\Acceptance\Support;
use Baander\Tooling\Runtime\Requirements;

try {
    Requirements::check();
    chdir(dirname(__DIR__));
    $option = $argv[1] ?? '';
    if (count($argv ?? []) > 2 || !in_array($option, ['', '--components-only'], true)) {
        fwrite(STDERR, 'Unknown option: ' . $option . "\n");
        exit(2);
    }
    foreach (['BAANDER_TEST_IMAGE', 'BAANDER_TEST_POSTGRES_IMAGE'] as $name) {
        Support::check((getenv($name) ?: '') !== '', $name . ': Select an already built disposable qualification image.');
    }
    $arguments = [];
    if ($option !== '--components-only') {
        foreach (['workspace' => 'BAANDER_QUALIFICATION_WORKSPACE', 'state-root' => 'BAANDER_QUALIFICATION_STATE_ROOT'] as $name => $variable) {
            Support::check((getenv($variable) ?: '') !== '', $variable . ': Select a new disposable qualification path.');
            $arguments[] = '--' . $name;
            $arguments[] = (string) getenv($variable);
        }
    }
    Support::run(
        [
            'docker', 'run', '--rm', '--network', 'none', '--entrypoint', 'php',
            (string) getenv('BAANDER_TEST_IMAGE'),
            'vendor/bin/phpunit', '-c', 'phpunit-tooling.xml.dist', '--filter', 'Development',
        ],
        timeout: null,
        passthrough: true,
    );
    Support::run(
        ['bash', 'scripts/test-dev-database-container.sh'],
        timeout: null,
        environment: ['BAANDER_DATABASE_TEST_IMAGE' => (string) getenv('BAANDER_TEST_POSTGRES_IMAGE')],
        passthrough: true,
    );
    Support::run(['bash', 'scripts/test-dev-worker-source-container.sh'], timeout: null, passthrough: true);
    if ($option === '--components-only') {
        echo "PASS: component safety and source admission. Integrated host acceptance was not selected.\n";
        exit(0);
    }
    foreach ([
        'host-state-root' => 'BAANDER_QUALIFICATION_HOST_STATE_ROOT',
        'prepare-shared-paths' => 'BAANDER_QUALIFICATION_PREPARE_SHARED_PATHS',
        'browser-check' => 'BAANDER_QUALIFICATION_BROWSER_CHECK',
    ] as $name => $variable) {
        if ((getenv($variable) ?: '') !== '') {
            $arguments[] = '--' . $name;
            $arguments[] = (string) getenv($variable);
        }
    }
    Support::run([PHP_BINARY, 'scripts/dev_environment_acceptance.php', ...$arguments], timeout: null, passthrough: true);
    echo "PASS: component contracts and integrated disposable workflow acceptance.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit($error instanceof \Baander\Tooling\Development\Acceptance\CommandFailure ? $error->status : 1);
}
