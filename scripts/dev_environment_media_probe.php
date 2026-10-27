#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\Tooling\Development\Acceptance\MediaProbe;
use Baander\Tooling\Development\Acceptance\Support;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Runtime\Requirements;

$output = null;
try {
    Requirements::check(extensions: ['openssl']);
    if (in_array('--help', $argv ?? [], true) || in_array('-h', $argv ?? [], true)) {
        echo "Exercise queued cataloging and complete audio responses on an owned dev instance.\n"
            . "Usage: dev_environment_media_probe.php --workspace PATH --state-root PATH"
            . " [--instance ID] [--ca-file PATH] [--timeout SECONDS] [--existing-scan]\n";
        exit(0);
    }
    $args = Support::options(
        array_slice($argv ?? [], 1),
        [
            'state-root' => '',
            'workspace' => '',
            'instance' => 'persistent',
            'ca-file' => '',
            'timeout' => '180',
            'existing-scan' => false,
        ],
        ['existing-scan'],
        ['workspace', 'state-root'],
    );
    umask(0077);
    $output = Support::path((string) $args['workspace']) . '/media-evidence.json';
    $result = MediaProbe::qualify($args);
    AtomicFile::writeJson($output, $result);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    if ($output !== null && is_file(dirname($output) . '/disposable-qualification')) {
        AtomicFile::writeJson($output, ['passed' => false, 'error' => $error->getMessage()]);
    }
    fwrite(STDERR, $error->getMessage() . "\n");
    exit($error instanceof InvalidArgumentException ? 2 : 1);
}
