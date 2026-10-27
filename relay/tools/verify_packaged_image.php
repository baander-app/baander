#!/usr/bin/env php
<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Baander\Registry\Inventory\ImageVerifier;
use Baander\Registry\Inventory\Inventory;
use Baander\Registry\Inventory\InventoryError;

require_once __DIR__ . '/inventory/ImageVerifier.php';
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
    ImageVerifier::qualify($options['image'], $options['kind'], $options['output']);
} catch (Throwable $error) {
    fwrite(STDERR, 'Image inventory qualification failed: ' . $error->getMessage() . "\n");
    exit(1);
}
