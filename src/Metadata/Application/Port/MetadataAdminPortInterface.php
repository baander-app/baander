<?php

declare(strict_types=1);

namespace App\Metadata\Application\Port;

/**
 * The metadata administration reads behind GET /api/admin/metadata/* and the
 * `app:metadata:status` and `app:metadata:providers` commands. Syncs start through
 * SyncMetadataCommand.
 */
interface MetadataAdminPortInterface
{
    /**
     * @return array{lastSyncAt: string|null, totalTracks: int, syncedTracks: int, pendingTracks: int, failedTracks: int, sources: array<array{name: string, synced: int, failed: int}>}
     */
    public function getSyncStatus(): array;

    /**
     * @return array<array{name: string, enabled: bool, configured: bool}>
     */
    public function getProviders(): array;
}
