<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Tv;

use App\Catalog\Application\Command\Tv\RegisterTvSeriesCommand;
use App\Catalog\Application\DTO\TvTitleRecord;
use App\Catalog\Application\Port\PersonCreditPortInterface;
use App\Catalog\Application\Port\TvCatalogPortInterface;
use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Domain\ValueObject\LibraryType;
use App\Metadata\Application\Port\PersonProfileSyncRequestInterface;
use App\Metadata\Application\Port\TvMetadataPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Port\TransactionPortInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RegisterTvSeriesHandler
{
    public function __construct(
        private LibraryPortInterface $libraries,
        private TvCatalogPortInterface $catalog,
        private TvMetadataPortInterface $metadata,
        private PersonCreditPortInterface $credits,
        private TransactionPortInterface $transaction,
        private ?PersonProfileSyncRequestInterface $profiles = null,
    ) {}

    public function __invoke(RegisterTvSeriesCommand $command): TvTitleRecord
    {
        if ($command->tmdbId <= 0) {
            throw new InvalidInputException('TMDB series ID must be positive.');
        }
        $library = $this->libraries->findByUuid($command->libraryId);
        if ($library === null) {
            throw new NotFoundException('TV library was not found.');
        }
        if ($library->getType() !== LibraryType::TvShow) {
            throw new InvalidInputException('Series registration requires a TV show library.');
        }
        $data = $this->metadata->series($command->tmdbId);
        if ($data === null) {
            throw new ServiceUnavailableException('TMDB did not return the requested TV series.');
        }

        $creditsAccepted = false;
        $title = $this->transaction->run(function () use ($command, $data, &$creditsAccepted): TvTitleRecord {
            $series = $this->catalog->registerSeries($command->libraryId, $data);
            $creditsAccepted = $this->credits->syncTvCredits('series', $series->id, $data->cast, $data->crew, $command->forceUpdate);

            return $series;
        });
        if ($creditsAccepted) {
            $this->profiles?->request();
        }

        return $title;
    }
}
