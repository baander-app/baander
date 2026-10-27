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
    $qualification = new Qualification($args);
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
    exit($error instanceof InvalidArgumentException ? 2 : 1);
}
