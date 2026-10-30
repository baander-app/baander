#!/usr/bin/env php
<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Baander\Registry\Inventory\ImageVerifier;
use Baander\Registry\Qualification\DockerOwnership;
use Baander\RegistryTests\Runtime;
use Baander\Registry\Inventory\Inventory;
use Baander\Registry\Inventory\InventoryError;

require dirname(__DIR__) . '/vendor/autoload.php';
$argv = $_SERVER['argv'] ?? [];

try {
    if (version_compare(PHP_VERSION, '8.5.0', '<')) {
        throw new InventoryError('PHP 8.5 or newer is required.');
    }
    if (in_array($argv[1] ?? '', ['--help', '-h'], true)) {
        echo "Usage: verify_packaged_image.php --image IMAGE --kind api|rqlite|backup --output DIRECTORY\n";
        exit(0);
    }
    $options = Inventory::options(array_slice($argv, 1));
    foreach (['image', 'kind', 'output'] as $key) {
        if (empty($options[$key])) {
            throw new InventoryError('missing --' . $key);
        }
    }
    $targetFile = getenv('BAANDER_TOOLING_TARGET_FILE');
    $ownershipReceipt = getenv('BAANDER_REGISTRY_OWNERSHIP_RECEIPT');
    $cleanupSeconds = getenv('BAANDER_CLEANUP_TIMEOUT');
    Runtime::requireThat(is_string($targetFile) && is_string($ownershipReceipt) && is_string($cleanupSeconds)
        && is_numeric($cleanupSeconds), 'Captured Docker target, ownership receipt and cleanup budget are required.');
    $target = DockerOwnership::read($targetFile);
    $environment = getenv();
    foreach (['DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_TLS', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH'] as $name) {
        unset($environment[$name]);
    }
    $owner = new DockerOwnership(
        $ownershipReceipt,
        $target,
        static fn(array $argv, ?string $input, float $seconds): array => Runtime::run($argv, $input, $seconds, $environment),
    );
    new ImageVerifier($owner, (float) $cleanupSeconds)->qualify($options['image'], $options['kind'], $options['output']);
} catch (Throwable $error) {
    fwrite(STDERR, 'Image inventory qualification failed: ' . $error->getMessage() . "\n");
    exit(1);
}
