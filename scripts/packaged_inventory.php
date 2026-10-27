#!/usr/bin/env php
<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\Tooling\Inventory\ApplicationInventory;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Inventory\InventoryError;
use Baander\Tooling\Inventory\Json;
use Baander\Tooling\Inventory\SafeTar;
use Baander\Tooling\Runtime\Requirements;

try {
    $argv = $_SERVER['argv'];
    Requirements::check();
    ini_set('memory_limit', '-1');
    $command = $argv[1] ?? '';
    if ($command === 'baseline') {
        exit(0);
    }
    if (in_array('--help', array_slice($argv, 2), true) || in_array('-h', array_slice($argv, 2), true)) {
        $command = '--help';
    }
    if (in_array($command, ['--help', '-h', ''], true)) {
        fwrite(STDOUT, "Usage: packaged_inventory.php extract|generate|verify|collect --option value ...\n");
        exit($command === '' ? 2 : 0);
    }
    $options = [];
    $allowed = match ($command) {
        'extract' => ['archive', 'root'],
        'generate' => ['root', 'image-inspect', 'target', 'revision', 'output'],
        'verify' => ['root', 'inventory', 'revision', 'image-inspect'],
        'collect' => ['image', 'target', 'revision', 'output', 'session'],
        'cleanup-collection' => ['session'],
        default => throw new InventoryError('Unknown command: ' . $command, 2),
    };
    for ($index = 2; $index < count($argv); $index += 2) {
        if (!str_starts_with($argv[$index], '--') || !isset($argv[$index + 1])) {
            throw new InventoryError('Expected --option value pairs', 2);
        }
        $key = substr($argv[$index], 2);
        if (!in_array($key, $allowed, true)) {
            throw new InventoryError('Unknown option: --' . $key, 2);
        }
        $options[$key] = $argv[$index + 1];
    }
    $required = static fn(string $name): string => $options[$name] ?? throw new InventoryError('Missing --' . $name, 2);
    if ($command === 'cleanup-collection') {
        Collector::cleanup($required('session'));
        exit(0);
    }
    if ($command === 'extract') {
        SafeTar::extract($required('archive'), $required('root'));
        exit(0);
    }
    if ($command === 'collect') {
        $result = Collector::collect($required('image'), $required('target'), $required('revision'), $required('output'), $required('session'));
    } elseif ($command === 'generate') {
        $identity = ApplicationInventory::imageIdentity(Json::read($required('image-inspect')), $required('target'));
        $result = ApplicationInventory::generate($required('root'), $identity, $required('output'), $required('revision'));
    } elseif ($command === 'verify') {
        $inventory = Json::read($required('inventory'));
        $identity = ApplicationInventory::imageIdentity(Json::read($required('image-inspect')), $inventory['identity']['target']);
        $result = ApplicationInventory::verify($required('root'), $required('inventory'), $required('revision'), $identity);
    } else {
        throw new InventoryError('Unknown command: ' . $command);
    }
    fwrite(
        STDOUT,
        Json::canonical([
            'integrity' => 'passed',
            'redistribution_review' => $result['redistribution_review'],
            'files' => count($result['files']),
            'review_gaps' => count($result['review_gaps']),
        ]),
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'Application inventory failed: ' . $error->getMessage() . "\n");
    exit($error->getCode() > 0 && $error->getCode() <= 255 ? $error->getCode() : 1);
}
