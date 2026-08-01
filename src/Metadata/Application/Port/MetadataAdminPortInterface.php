<?php

declare(strict_types=1);

namespace App\Metadata\Application\Port;

interface MetadataAdminPortInterface
{
    /**
     * @return array{lastSyncAt: string|null, totalTracks: int, syncedTracks: int, pendingTracks: int, failedTracks: int, sources: array<array{name: string, synced: int, failed: int}>}
     */
    public function getSyncStatus(): array;

    /**
     * @return int Number of jobs dispatched
     */
    public function triggerSync(?string $source): int;

    /**
     * @return array<array{name: string, enabled: bool, configured: bool}>
     */
    public function getProviders(): array;
}
