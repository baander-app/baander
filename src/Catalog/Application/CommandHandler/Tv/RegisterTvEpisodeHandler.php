<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Tv;

use App\Catalog\Application\Command\Tv\RegisterTvEpisodeCommand;
use App\Catalog\Application\DTO\TvTitleRecord;
use App\Catalog\Application\Port\PersonCreditPortInterface;
use App\Catalog\Application\Port\TvCatalogPortInterface;
use App\Metadata\Application\Port\PersonProfileSyncRequestInterface;
use App\Metadata\Application\Port\TvMetadataPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Port\TransactionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RegisterTvEpisodeHandler
{
    public function __construct(
        private TvCatalogPortInterface $catalog,
        private TvMetadataPortInterface $metadata,
        private PersonCreditPortInterface $credits,
        private TransactionPortInterface $transaction,
        private ?PersonProfileSyncRequestInterface $profiles = null,
    ) {}

    public function __invoke(RegisterTvEpisodeCommand $command): TvTitleRecord
    {
        if ($command->seasonNumber < 0 || $command->episodeNumber < 1) {
            throw new InvalidInputException('Season number must be nonnegative and episode number must be positive.');
        }
        $series = $this->catalog->findSeries($command->seriesId);
        if ($series === null) {
            throw new NotFoundException('Registered TV series was not found.');
        }
        $data = $this->metadata->episode($series->seriesTmdbId, $command->seasonNumber, $command->episodeNumber);
        if ($data === null) {
            throw new ServiceUnavailableException('TMDB did not return the requested TV episode.');
        }

        $creditsAccepted = false;
        $title = $this->transaction->run(function () use ($command, $series, $data, &$creditsAccepted): TvTitleRecord {
            $episode = $this->catalog->registerEpisode($series, $command->seasonNumber, $command->episodeNumber, $data);
            $creditsAccepted = $this->credits->syncTvCredits('episode', $episode->id, $data->cast, $data->crew, $command->forceUpdate);

            return $episode;
        });
        if ($creditsAccepted) {
            $this->profiles?->request();
        }

        return $title;
    }
}
