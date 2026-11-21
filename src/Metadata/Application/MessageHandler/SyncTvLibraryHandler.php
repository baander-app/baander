<?php

declare(strict_types=1);

namespace App\Metadata\Application\MessageHandler;

use App\Catalog\Application\Command\Tv\RegisterTvEpisodeCommand;
use App\Catalog\Application\Command\Tv\RegisterTvSeriesCommand;
use App\Catalog\Application\Port\TvCatalogPortInterface;
use App\Metadata\Application\Message\SyncTvLibraryMessage;
use App\Shared\Application\Port\JobCancellationCheckpointInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class SyncTvLibraryHandler
{
    public function __construct(
        private TvCatalogPortInterface $catalog,
        private MessageBusInterface $bus,
        private JobCancellationCheckpointInterface $cancellation,
    ) {}

    #[AsMessageHandler(fromTransport: 'async')]
    public function __invoke(SyncTvLibraryMessage $message): void
    {
        $after = null;
        do {
            $seriesPage = $this->catalog->seriesByLibraryAfter($message->libraryId, $after);
            foreach ($seriesPage as $series) {
                $this->cancellation->check();
                $this->bus->dispatch(new RegisterTvSeriesCommand(
                    libraryId: $series->libraryId,
                    tmdbId: $series->seriesTmdbId,
                    forceUpdate: $message->forceUpdate,
                ));
                $episodeAfter = null;
                do {
                    $episodePage = $this->catalog->episodesBySeriesAfter($series->id, $episodeAfter);
                    foreach ($episodePage as $episode) {
                        $this->cancellation->check();
                        $this->bus->dispatch(new RegisterTvEpisodeCommand(
                            seriesId: $series->publicId,
                            seasonNumber: $episode->seasonNumber ?? throw new \LogicException('Episode season is missing.'),
                            episodeNumber: $episode->episodeNumber ?? throw new \LogicException('Episode number is missing.'),
                            forceUpdate: $message->forceUpdate,
                        ));
                        $episodeAfter = $episode->id;
                    }
                } while (count($episodePage) === 100);
                $after = $series->id;
            }
        } while (count($seriesPage) === 100);
    }
}
