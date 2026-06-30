<?php

declare(strict_types=1);

namespace App\Metadata\Application\MessageHandler;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Metadata\Application\AlbumMetadataEnricher;
use App\Metadata\Application\Message\SyncAlbumMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class SyncAlbumHandler
{
    public function __construct(
        private readonly AlbumPortInterface $albumService,
        private readonly AlbumMetadataEnricher $enricher,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler(fromTransport: 'swoole_task')]
    public function __invoke(SyncAlbumMessage $message): void
    {
        $album = $this->albumService->findByUuid($message->albumId);

        if ($album === null) {
            $this->logger->warning('Album not found for sync', ['album_id' => $message->albumId]);

            return;
        }

        $this->enricher->enrich($album, $message->forceUpdate);
    }
}
