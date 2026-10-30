#!/usr/bin/env php
<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Baander\Registry\Inventory\Inventory;
use Baander\Registry\Inventory\InventoryError;

require dirname(__DIR__) . '/vendor/autoload.php';

$argv = $_SERVER['argv'] ?? [];

try {
    if (version_compare(PHP_VERSION, '8.5.0', '<')) {
        throw new InventoryError('PHP 8.5 or newer is required.');
    }
    $command = $argv[1] ?? '';
    if (in_array($command, ['--help', '-h'], true)) {
        echo "Usage: packaged_inventory.php generate|verify|capture-api|check-resolutions --option value\n";
        exit(0);
    }
    $options = Inventory::options(array_slice($argv, 2));
    $required = match ($command) {
        'generate' => ['kind', 'root', 'resolutions', 'output'],
        'verify' => ['root', 'inventory'],
        'capture-api' => ['build', 'output'],
        'check-resolutions' => ['resolutions'],
        default => throw new InventoryError('expected generate, verify, capture-api or check-resolutions'),
    };
    foreach ($required as $key) {
        if (empty($options[$key])) {
            throw new InventoryError('missing --' . $key);
        }
    }
    if ($command === 'capture-api') {
        Inventory::captureApi($options['build'], $options['output']);
        exit(0);
    }
    $result = match ($command) {
        'generate' => Inventory::generate($options['root'], $options['kind'], $options['resolutions'], $options['output']),
        'verify' => Inventory::verify(
            $options['root'],
            $options['inventory'],
            $options['resolutions'] ?? dirname(__DIR__) . '/third_party/inventory',
        ),
        default => ['components' => Inventory::resolutionData($options['resolutions'])],
    };
    echo Inventory::canonical([
        'status' => 'passed',
        'components' => count($result['components']),
        'packages' => count($result['packages'] ?? []),
    ]);
} catch (Throwable $error) {
    fwrite(STDERR, 'Inventory qualification failed: ' . $error->getMessage() . "\n");
    exit(1);
}
