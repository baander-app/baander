<?php

declare(strict_types=1);

namespace App\Metadata\Application\Service;

use App\Metadata\Application\MetadataSyncOrchestrator;
use App\Metadata\Application\Port\AlbumMetadataSyncRequestInterface;
use App\Metadata\Application\Settings\MetadataSettingDefinitions;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Domain\Model\Uuid;

/**
 * Queues automatic metadata syncs for new albums while `metadata.auto_sync` is on.
 *
 * The orchestrator dispatches SyncAlbumMessage, which is routed to swoole_task and
 * falls back to the Redis async transport outside the Swoole server, so ingest
 * never waits for the external lookups.
 */
final readonly class AlbumMetadataSyncRequester implements AlbumMetadataSyncRequestInterface
{
    public function __construct(
        private SystemSettingsPortInterface $settings,
        private MetadataSyncOrchestrator $orchestrator,
    ) {
    }

    public function requestSync(Uuid ...$albumIds): void
    {
        if ($albumIds === [] || $this->settings->get(MetadataSettingDefinitions::AUTO_SYNC) !== true) {
            return;
        }

        foreach ($albumIds as $albumId) {
            $this->orchestrator->syncAlbum($albumId->toString());
        }
    }
}
