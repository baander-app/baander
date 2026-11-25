<?php

declare(strict_types=1);

namespace App\Metadata\Application\MessageHandler;

use App\Catalog\Application\Port\MoviePortInterface;
use App\Metadata\Application\Message\SyncMovieMessage;
use App\Metadata\Application\MovieMetadataEnricher;
use App\Shared\Application\Exception\ServiceUnavailableException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class SyncMovieHandler
{
    public function __construct(
        private MoviePortInterface $movies,
        private MovieMetadataEnricher $enricher,
    ) {}

    #[AsMessageHandler(fromTransport: 'async', sign: true)]
    public function __invoke(SyncMovieMessage $message): void
    {
        $movie = $this->movies->findByUuid($message->movieId);
        if ($movie === null) {
            return;
        }
        $result = $this->enricher->enrich($movie, $message->forceUpdate);
        if ($result->isFailure()) {
            throw new ServiceUnavailableException('Movie metadata enrichment failed.');
        }
    }
}
