<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\VideoMatch;

use App\Catalog\Application\Dto\PreparedVideoMatchMetadata;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\PersonCreditInput;
use App\Catalog\Application\Port\PersonCreditPortInterface;
use App\Catalog\Application\Port\PersonCreditTargetInput;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Media\Application\Port\VideoArtworkImportInterface;
use App\Metadata\Application\DTO\Video\VideoArtwork;
use App\Metadata\Application\DTO\Video\VideoCredit;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Port\PersonProfileSyncRequestInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\Model\Uuid;
use LogicException;
use Throwable;

final readonly class ApplyVideoMatchHandler
{
    public function __construct(
        private MovieRepositoryInterface $movies,
        private TvCatalogRepositoryInterface $catalog,
        private PersonCreditPortInterface $people,
        private GenrePortInterface $genres,
        private VideoArtworkImportInterface $artwork,
        private PersonProfileSyncRequestInterface $profiles,
    ) {}

    public function prepare(Uuid $libraryId, VideoTitle $metadata): PreparedVideoMatchMetadata
    {
        $prepared = new PreparedVideoMatchMetadata($libraryId, $metadata, []);
        try {
            foreach (self::artwork($metadata) as $image) {
                if ($image->libraryId === null) {
                    continue;
                }
                if (!$image->libraryId->equals($libraryId)) {
                    throw new InvalidInputException('Local artwork belongs to a different library.');
                }
                if (!isset($prepared->artwork[$image->path])) {
                    $artwork = $this->artwork->prepare($libraryId, $image->path);
                    $prepared->artwork[$image->path] = $artwork;
                    $prepared->artworkPathsByUrl[$artwork->url][] = $image->path;
                }
            }
        } catch (Throwable $failure) {
            $this->discard($prepared);
            throw $failure;
        }

        return $prepared;
    }

    /** The caller holds the fresh title and its claim in the same database transaction. */
    public function apply(Movie|TvSeries $title, VideoProviderId $provider, PreparedVideoMatchMetadata $prepared, bool $force = false): void
    {
        if (!$title->getLibraryId()->equals($prepared->libraryId)) {
            throw new LogicException('Prepared metadata belongs to another library.');
        }
        if ($title instanceof Movie) {
            $targets = $this->movie($title, $provider, $prepared, $force);
        } else {
            $targets = $this->series($title, $provider, $prepared, $force);
        }
        $prepared->creditsChanged = $this->people->syncCredits($targets);
        if ($title instanceof Movie && $prepared->metadata->genres !== []) {
            $genres = $this->genres->findOrCreateForImport($prepared->metadata->genres);
            $unique = [];
            foreach ($genres as $genreId) {
                $unique[$genreId->toString()] = $genreId;
            }
            foreach ($unique as $genreId) {
                $this->genres->addMovieToGenre($genreId, $title->getId());
            }
        }
        foreach ($prepared->artwork as $path => $artwork) {
            if (isset($prepared->usedArtwork[$path])) {
                $this->artwork->persist($artwork);
            }
        }
    }

    public function discard(PreparedVideoMatchMetadata $prepared, bool $committed = false): void
    {
        foreach ($prepared->artwork as $path => $artwork) {
            if (!$committed || !isset($prepared->usedArtwork[$path])) {
                $this->artwork->discard($artwork);
            }
        }
        $prepared->artwork = [];
        $prepared->usedArtwork = [];
        $prepared->artworkPathsByUrl = [];
        if ($committed && $prepared->creditsChanged) {
            $prepared->creditsChanged = false;
            $this->profiles->request();
        }
    }

    /** @return list<PersonCreditTargetInput> */
    private function movie(Movie $movie, VideoProviderId $provider, PreparedVideoMatchMetadata $prepared, bool $force): array
    {
        $metadata = $prepared->metadata;
        $movie->updateMetadata(
            title: self::fill($movie->getTitle(), $metadata->entry->title, $force),
            year: self::fill($movie->getYear(), $metadata->entry->year, $force),
            overview: self::fill($movie->getOverview(), $metadata->overview, $force),
            tagline: self::fill($movie->getTagline(), $metadata->tagline, $force),
            posterUrl: self::fill($movie->getPosterUrl(), self::url($metadata->poster, $prepared), $force),
            backdropUrl: self::fill($movie->getBackdropUrl(), self::url($metadata->backdrop, $prepared), $force),
            runtime: self::fill($movie->getRuntime(), $metadata->runtime, $force),
            rating: self::fill($movie->getRating(), $metadata->rating, $force),
            originalLanguage: self::fill($movie->getOriginalLanguage(), $metadata->originalLanguage, $force),
            tmdbCollectionId: self::fill($movie->getTmdbCollectionId(), $metadata->collectionId, $force),
            collectionName: self::fill($movie->getCollectionName(), $metadata->collectionName, $force),
        );
        $ids = $metadata->entry->externalIds;
        $tmdbId = $ids['tmdb'] ?? ($provider === VideoProviderId::Tmdb ? $metadata->entry->id : null);
        $movie->updateExternalIds(
            tmdbId: $tmdbId !== null && ctype_digit($tmdbId) ? (int) $tmdbId : null,
            imdbId: $ids['imdb'] ?? null,
        );
        $this->movies->saveMetadata($movie);
        self::used($prepared, [$movie->getPosterUrl(), $movie->getBackdropUrl()]);

        return [new PersonCreditTargetInput('movie', $movie->getId(), self::credits($metadata->cast, $provider), self::credits($metadata->crew, $provider), $force)];
    }

    /** @return list<PersonCreditTargetInput> */
    private function series(TvSeries $series, VideoProviderId $provider, PreparedVideoMatchMetadata $prepared, bool $force): array
    {
        $metadata = $prepared->metadata;
        $series->applyProviderMetadata([
            'name' => $metadata->entry->title, 'year' => $metadata->entry->year,
            'overview' => $metadata->overview, 'posterUrl' => self::url($metadata->poster, $prepared),
            'backdropUrl' => self::url($metadata->backdrop, $prepared),
        ], $force);
        $this->catalog->saveSeries($series);
        self::used($prepared, [$series->getPosterUrl(), $series->getBackdropUrl()]);
        $targets = [new PersonCreditTargetInput('series', $series->getId(), self::credits($metadata->cast, $provider), self::credits($metadata->crew, $provider), $force)];
        $episodes = [];
        foreach ($this->catalog->episodesBySeries($series->getId()) as $episode) {
            if ($episode->getProviderId() !== null) {
                $episodes[$episode->getProviderId()] = $episode;
            }
        }
        $seasons = [];
        foreach ($this->catalog->seasonsBySeries($series->getId()) as $season) {
            $seasons[$season->getSeasonNumber()] = $season;
        }
        foreach ($metadata->seasons as $providedSeason) {
            $season = $seasons[$providedSeason->number] ?? null;
            if ($season !== null) {
                $season->applyProviderMetadata([
                    'name' => $providedSeason->name, 'overview' => $providedSeason->overview,
                    'posterUrl' => self::url($providedSeason->poster, $prepared),
                ], $force);
                $this->catalog->saveSeason($season);
                self::used($prepared, [$season->getPosterUrl()]);
            }
            foreach ($providedSeason->episodes as $providedEpisode) {
                $episode = $episodes[$providedEpisode->id] ?? null;
                if ($episode === null) {
                    continue;
                }
                $episode->applyProviderMetadata([
                    'name' => $providedEpisode->title, 'overview' => $providedEpisode->overview,
                    'stillUrl' => self::url($providedEpisode->still, $prepared),
                ], $force);
                $this->catalog->saveEpisode($episode);
                self::used($prepared, [$episode->getStillUrl()]);
                $targets[] = new PersonCreditTargetInput('episode', $episode->getId(), self::credits($providedEpisode->cast, $provider), self::credits($providedEpisode->crew, $provider), $force);
            }
        }
        $this->catalog->flush();

        return $targets;
    }

    /**
     * @template T
     * @param T $incoming
     * @return T|null
     */
    private static function fill(mixed $current, mixed $incoming, bool $force): mixed
    {
        return $force || $current === null || $current === '' ? $incoming : null;
    }

    private static function url(?VideoArtwork $image, PreparedVideoMatchMetadata $prepared): ?string
    {
        if ($image === null) {
            return null;
        }

        return $image->libraryId === null ? $image->path : ($prepared->artwork[$image->path]->url ?? throw new LogicException('Local artwork was not prepared.'));
    }

    /** @param list<?string> $urls */
    private static function used(PreparedVideoMatchMetadata $prepared, array $urls): void
    {
        foreach ($urls as $url) {
            if ($url === null) {
                continue;
            }
            foreach ($prepared->artworkPathsByUrl[$url] ?? [] as $path) {
                $prepared->usedArtwork[$path] = true;
            }
        }
    }

    /** @return iterable<VideoArtwork> */
    private static function artwork(VideoTitle $metadata): iterable
    {
        foreach ([$metadata->poster, $metadata->backdrop] as $artwork) {
            if ($artwork !== null) {
                yield $artwork;
            }
        }
        foreach ($metadata->seasons as $season) {
            if ($season->poster !== null) {
                yield $season->poster;
            }
            foreach ($season->episodes as $episode) {
                if ($episode->still !== null) {
                    yield $episode->still;
                }
            }
        }
    }

    /**
     * @param list<VideoCredit>|null $credits
     * @return list<PersonCreditInput>|null
     */
    private static function credits(?array $credits, VideoProviderId $provider): ?array
    {
        if ($credits === null) {
            return null;
        }
        $inputs = [];
        foreach ($credits as $credit) {
            if ($credit->identifiers === []) {
                if ($provider === VideoProviderId::Local) {
                    continue;
                }
                return null;
            }
            $inputs[] = new PersonCreditInput(
                identifiers: $credit->identifiers, name: $credit->name, category: $credit->category,
                role: $credit->role, job: $credit->job, characterName: $credit->characterName,
                department: $credit->department, creditedName: $credit->creditedName,
                billingOrder: $credit->billingOrder, providerCreditId: $credit->providerCreditId,
                profileUrl: $credit->profileUrl, source: $provider->value,
            );
        }

        return $inputs;
    }
}
