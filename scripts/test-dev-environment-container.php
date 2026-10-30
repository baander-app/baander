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
    $context = \Baander\Tooling\Process\ExecutionContext::bounded($configuration->budgets->seconds('backend'), $environment);
    $executor = new \Baander\Tooling\Process\EngineExecutor($context);
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
    $artifacts = Support::temporary('baander-development-check-');
    $target = new \Baander\Tooling\Docker\TargetResolver($executor, $environment)->resolve($configuration->docker, $artifacts . '/transport');
    \Baander\Tooling\Filesystem\AtomicFile::writeJson($artifacts . '/target.json', $target->toArray());
    $environment['BAANDER_TOOLING_TARGET_FILE'] = $artifacts . '/target.json';
    $executor->close();
    $executor = new \Baander\Tooling\Process\EngineExecutor(new \Baander\Tooling\Process\ExecutionContext($environment, deadline: $context->deadline));
    $docker = new \Baander\Tooling\Docker\Client($executor, $target, $environment);
    $image = $docker->resolveImage($configuration->images->role('application'), pull: false)->id;
    $ledger = new \Baander\Tooling\Resources\ResourceLedger($artifacts . '/resources.json', $docker, $environment);
    $ledger->scoped(function () use ($ledger, $docker, $image): void {
        $container = $ledger->create(\Baander\Tooling\Resources\ResourceKind::Container, 'baander-development-check-' . bin2hex(random_bytes(6)), [
            '--network', 'none', '--entrypoint', 'php', $image,
            'vendor/bin/phpunit', '-c', 'phpunit-tooling.xml.dist', '--filter', 'Development',
        ]);
        $docker->run(['start', '--attach', $container], interactive: true);
        $status = (int) trim($docker->run(['wait', $container])->stdout);
        if ($status !== 0) {
            throw new \Baander\Tooling\Process\CommandFailure('Development container tests failed.', $status);
        }
    }, $configuration->budgets->seconds('cleanup'));
    $executor->run(new \Baander\Tooling\Process\ProcessSpec(['bash', 'scripts/test-dev-database-container.sh'], timeout: null, environment: ['BAANDER_DATABASE_TEST_IMAGE' => (string) getenv('BAANDER_TEST_POSTGRES_IMAGE')], passthrough: true, processGroup: true), new \Baander\Tooling\Process\CompletionPolicy([0]));
    $executor->run(new \Baander\Tooling\Process\ProcessSpec(['bash', 'scripts/test-dev-worker-source-container.sh'], timeout: null, passthrough: true, processGroup: true), new \Baander\Tooling\Process\CompletionPolicy([0]));
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
    $executor->run(new \Baander\Tooling\Process\ProcessSpec([PHP_BINARY, 'scripts/dev_environment_acceptance.php', ...$arguments], timeout: null, passthrough: true, processGroup: true), new \Baander\Tooling\Process\CompletionPolicy([0]));
    echo "PASS: component contracts and integrated disposable workflow acceptance.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit($error->getCode() > 0 && $error->getCode() < 256 ? $error->getCode() : 1);
}
