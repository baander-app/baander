<?php

declare(strict_types=1);

namespace App\Metadata\Application\MessageHandler;

use App\Catalog\Application\Port\ArtistPortInterface;
use App\Metadata\Application\ArtistMetadataEnricher;
use App\Metadata\Application\Message\SyncArtistMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class SyncArtistHandler
{
    public function __construct(
        private readonly ArtistPortInterface $artistService,
        private readonly ArtistMetadataEnricher $enricher,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(SyncArtistMessage $message): void
    {
        $artist = $this->artistService->findByUuid($message->artistId);

        if ($artist === null) {
            $this->logger->warning('Artist not found for sync', ['artist_id' => $message->artistId]);

            return;
        }

        $this->enricher->enrich($artist, $message->forceUpdate);
    }
}
