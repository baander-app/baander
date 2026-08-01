<?php

declare(strict_types=1);

namespace App\Metadata\Application\MessageHandler;

use App\Catalog\Application\Port\SongPortInterface;
use App\Metadata\Application\SongMetadataEnricher;
use App\Metadata\Application\Message\SyncSongMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class SyncSongHandler
{
    public function __construct(
        private readonly SongPortInterface $songService,
        private readonly SongMetadataEnricher $enricher,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler(fromTransport: 'swoole_task')]
    public function __invoke(SyncSongMessage $message): void
    {
        $song = $this->songService->findByUuid($message->songId);

        if ($song === null) {
            $this->logger->warning('Song not found for sync', ['song_id' => $message->songId]);

            return;
        }

        $this->enricher->enrich($song, $message->forceUpdate);
    }
}
