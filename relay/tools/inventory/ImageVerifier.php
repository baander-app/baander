<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Baander\Registry\Inventory;

use Baander\Registry\Qualification\DockerOwnership;

final class ImageVerifier
{
    public function __construct(private readonly DockerOwnership $docker, private readonly float $cleanupSeconds)
    {
        if (!is_finite($cleanupSeconds) || $cleanupSeconds <= 0) {
            throw new InventoryError('cleanup needs a positive finite budget');
        }
    }

    /** @param list<string> $arguments */
    private function checked(array $arguments): string
    {
        $result = $this->docker->run($arguments);
        if ($result['code'] !== 0) {
            throw new InventoryError('Docker command failed (' . $result['code'] . '): ' . $result['stderr']);
        }
        return $result['stdout'];
    }

    public function qualify(string $image, string $kind, string $output, ?string $resolutions = null): void
    {
        if (!isset(Inventory::EXECUTABLES[$kind])) {
            throw new InventoryError('unsupported image kind');
        }
        $temporary = sys_get_temp_dir() . '/registry-inventory-' . bin2hex(random_bytes(12));
        $primary = null;
        $receipt = null;
        try {
            $imageId = $this->checked(['image', 'inspect', '--format', '{{.Id}}', $image]) |> trim(...);
            if (!preg_match('/^sha256:[a-f0-9]{64}$/D', $imageId)) {
                throw new InventoryError('image inspect did not return an immutable image ID');
            }
            $container = $this->checked(['create', $imageId]) |> trim(...);
            if (!preg_match('/^[a-f0-9]{64}$/D', $container)) {
                throw new InventoryError('docker create did not return a container ID');
            }
            Inventory::directory($temporary . '/rootfs');
            $root = $temporary . '/rootfs';
            $this->checked(['cp', $container . ':/.', $root]);
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
        } catch (\Throwable $error) {
            $primary = $error;
        }
        $cleanup = [];
        try {
            $cleanup = $this->docker->cleanup($this->cleanupSeconds);
        } catch (\Throwable $error) {
            $cleanup[] = $error->getMessage();
        }
        try {
            if (is_dir($temporary)) {
                Inventory::removeTree($temporary);
            }
        } catch (\Throwable $error) {
            $cleanup[] = $error->getMessage();
        }
        if ($primary !== null || $cleanup !== []) {
            $message = $primary?->getMessage() ?? 'Image cleanup failed';
            if ($cleanup !== []) {
                $message .= '; cleanup: ' . implode('; ', $cleanup) . '; ownership receipt: ' . $this->docker->receipt;
            }
            throw new InventoryError($message, $primary?->getCode() ?? 1, $primary);
        }
        Inventory::write($destination . '/receipt.json', Inventory::canonical($receipt));
        echo $kind . ': verified ' . $receipt['packages'] . ' packages and '
            . $receipt['components'] . ' components in ' . $imageId
            . '; evidence ' . $destination . "\n";
    }
}
