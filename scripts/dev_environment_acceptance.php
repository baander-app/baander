#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\Tooling\Development\Acceptance\Qualification;
use Baander\Tooling\Development\Acceptance\Support;
use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Runtime\Requirements;

$qualification = null;
try {
    Requirements::check();
    if (in_array('--help', $argv ?? [], true) || in_array('-h', $argv ?? [], true)) {
        echo "Qualify the real development controller on explicitly disposable resources.\n"
            . "Usage: dev_environment_acceptance.php --workspace PATH --state-root PATH"
            . " [--host-state-root PATH] [--prepare-shared-paths EXECUTABLE]"
            . " [--media-check PATH] [--browser-check PATH] [--timeout SECONDS]\n";
        exit(0);
    }
    $args = Support::options(
        array_slice($argv ?? [], 1),
        [
            'workspace' => '',
            'state-root' => '',
            'host-state-root' => StateStore::default()->root,
            'prepare-shared-paths' => '',
            'media-check' => __DIR__ . '/dev_environment_media_probe.php',
            'browser-check' => __DIR__ . '/dev_environment_browser_probe.mjs',
            'timeout' => '7200',
        ],
        required: ['workspace', 'state-root'],
    );
    umask(0077);
    Support::check(!file_exists(Support::path((string) $args['workspace'])), 'Select a new workspace for disposable qualification.');
    $environment = getenv();
    $configuration = \Baander\Tooling\Configuration\ToolingConfiguration::forRepository(dirname(__DIR__), $environment);
    $executor = new \Baander\Tooling\Process\EngineExecutor(\Baander\Tooling\Process\ExecutionContext::bounded((float) $args['timeout'], $environment));
    $target = new \Baander\Tooling\Docker\TargetResolver($executor, $environment)->resolve($configuration->docker, $args['state-root'] . '/host-setup/acceptance-transport');
    $targetFile = $args['state-root'] . '/host-setup/acceptance-target.json';
    \Baander\Tooling\Filesystem\AtomicFile::writeJson($targetFile, $target->toArray());
    $environment['BAANDER_TOOLING_TARGET_FILE'] = $targetFile;
    $qualification = new Qualification($args, $executor, new \Baander\Tooling\Docker\Client($executor, $target, $environment), $environment);
    $qualification->run();
} catch (Throwable $error) {
    if ($qualification !== null) {
        try {
            $qualification->preserveFailure();
        } catch (Throwable $preservationError) {
            fwrite(STDERR, 'Failure preservation: ' . $preservationError->getMessage() . "\n");
        }
    }
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . ($qualification === null ? '' : "\nRetained evidence: " . $qualification->workspace) . "\n");
    exit($error instanceof InvalidArgumentException ? 2 : ($error->getCode() > 0 && $error->getCode() < 256 ? $error->getCode() : 1));
}
