<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Baander\Registry\Inventory;

require_once __DIR__ . '/Inventory.php';

final class ImageVerifier
{
    public static function qualify(string $image, string $kind, string $output, ?string $resolutions = null): void
    {
        if (!isset(Inventory::EXECUTABLES[$kind])) {
            throw new InventoryError('unsupported image kind');
        }
        $imageId = Inventory::run(['docker', 'image', 'inspect', '--format', '{{.Id}}', $image]) |> trim(...);
        if (!preg_match('/^sha256:[a-f0-9]{64}$/D', $imageId)) {
            throw new InventoryError('image inspect did not return an immutable image ID');
        }
        $container = Inventory::run(['docker', 'create', $imageId]) |> trim(...);
        if ($container === '' || str_contains($container, "\n")) {
            throw new InventoryError('docker create did not return a container ID');
        }
        $temporary = sys_get_temp_dir() . '/registry-inventory-' . bin2hex(random_bytes(12));
        try {
            Inventory::directory($temporary . '/rootfs');
            $root = $temporary . '/rootfs';
            Inventory::run(['docker', 'cp', $container . ':/.', $root]);
            $bundle = $root . Inventory::BUNDLE_PATH;
            $inventoryPath = $bundle . '/inventory.json';
            $inventory = Inventory::verify($root, $inventoryPath, $resolutions ?? dirname(__DIR__, 2) . '/third_party/inventory');
            if ($inventory['kind'] !== $kind) {
                throw new InventoryError('expected ' . $kind . ' image, found ' . $inventory['kind'] . ' inventory');
            }
            $destination = $output . '/' . $kind . '/' . substr($imageId, 7);
            Inventory::copyTree($bundle, $destination . '/bundle');
            $receipt = [
                'image_id' => $imageId,
                'kind' => $kind,
                'inventory_sha256' => $inventoryPath |> Inventory::read(...) |> Inventory::digest(...),
                'packages' => count($inventory['packages']),
                'components' => count($inventory['components']),
                'executables' => $inventory['executables'],
                'status' => 'passed',
            ];
            Inventory::write($destination . '/receipt.json', Inventory::canonical($receipt));
            echo $kind . ': verified ' . $receipt['packages'] . ' packages and '
                . $receipt['components'] . ' components in ' . $imageId
                . '; evidence ' . $destination . "\n";
        } finally {
            try {
                Inventory::run(['docker', 'rm', $container]);
            } finally {
                if (is_dir($temporary)) {
                    Inventory::removeTree($temporary);
                }
            }
        }
    }
}
