<?php

declare(strict_types=1);

namespace App\Shared\Application\LogViewer\Service;

interface LogSnapshotStoreInterface
{
    /** @param array<string, mixed> $snapshot */
    public function save(array $snapshot): string;

    /** @return array<string, mixed> */
    public function load(string $id): array;
}
