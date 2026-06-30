<?php

declare(strict_types=1);

namespace App\Metadata\Application;

use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Domain\Model\Movie;
use App\Metadata\Infrastructure\Api\Tmdb\TmdbAdapter;
use Psr\Log\LoggerInterface;

final class MovieMetadataEnricher
{
    private const QUALITY_THRESHOLD_WITH_ID = 0.3;
    private const QUALITY_THRESHOLD_WITHOUT_ID = 0.5;

    public function __construct(
        private readonly TmdbAdapter $tmdbAdapter,
        private readonly MoviePortInterface $movieService,
        private readonly GenrePortInterface $genreService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enrich(Movie $movie, bool $forceUpdate = false): EnrichmentResult
    {
        $this->logger->info('Starting movie enrichment', [
            'movie_id' => $movie->getId()->toString(),
            'title' => $movie->getTitle(),
        ]);

        try {
            $data = $this->searchTmdb($movie);
            if ($data === null) {
                return EnrichmentResult::noMatch('tmdb', 0.0);
            }

            // TMDB-ID dedup
            if ($data->id !== $movie->getTmdbId()) {
                $existingByTmdbId = $this->movieService->findByTmdbId($data->id);
                if ($existingByTmdbId !== null && $existingByTmdbId->getId()->toString() !== $movie->getId()->toString()) {
                    $this->logger->info('Movie with this TMDB ID already exists', [
                        'tmdb_id' => $data->id,
                        'existing_movie_id' => $existingByTmdbId->getId()->toString(),
                    ]);
                    $movie = $existingByTmdbId;
                }
            }

            return $this->applyData($movie, $data, $forceUpdate);
        } catch (\Throwable $e) {
            $this->logger->error('Movie enrichment failed', [
                'movie_id' => $movie->getId()->toString(),
                'error' => $e->getMessage(),
            ]);

            return EnrichmentResult::failure('tmdb');
        }
    }

    private function searchTmdb(Movie $movie): ?\App\Metadata\Infrastructure\Api\Tmdb\DTO\TmdbMovieDto
    {
        $searchResult = $this->tmdbAdapter->searchMovie(
            query: $movie->getTitle(),
            year: $movie->getYear(),
            limit: 1,
        );

        if ($searchResult->isEmpty()) {
            return null;
        }

        $firstResult = $searchResult->first();
        $threshold = $movie->getTmdbId() !== null ? self::QUALITY_THRESHOLD_WITH_ID : self::QUALITY_THRESHOLD_WITHOUT_ID;
        $qualityScore = min($firstResult->popularity / 100.0, 1.0);

        if ($qualityScore < $threshold && ($firstResult->voteCount ?? 0) < 10) {
            $this->logger->info('Movie search result below quality threshold', [
                'title' => $firstResult->title,
                'quality' => $qualityScore,
                'threshold' => $threshold,
            ]);

            return null;
        }

        $fullDetails = $this->tmdbAdapter->lookupMovie($firstResult->id);
        if ($fullDetails !== null) {
            return $fullDetails;
        }

        return $firstResult;
    }

    private function applyData(Movie $movie, \App\Metadata\Infrastructure\Api\Tmdb\DTO\TmdbMovieDto $data, bool $forceUpdate): EnrichmentResult
    {
        $updatedFields = [];
        $identifiersUpdated = false;

        if ($forceUpdate || $movie->getTmdbId() === null) {
            $movie->updateExternalIds(tmdbId: $data->id);
            $identifiersUpdated = true;
        }
        if ($data->imdbId !== null && ($forceUpdate || $movie->getImdbId() === null)) {
            $movie->updateExternalIds(imdbId: $data->imdbId);
            $identifiersUpdated = true;
        }

        $overview = $forceUpdate || $movie->getOverview() === null ? $data->overview : null;
        $tagline = $forceUpdate || $movie->getTagline() === null ? $data->tagline : null;
        $posterUrl = null;
        if ($data->posterPath !== null && ($forceUpdate || $movie->getPosterUrl() === null)) {
            $posterUrl = 'https://image.tmdb.org/t/p/w500' . $data->posterPath;
        }
        $backdropUrl = null;
        if ($data->backdropPath !== null && ($forceUpdate || $movie->getBackdropUrl() === null)) {
            $backdropUrl = 'https://image.tmdb.org/t/p/w1280' . $data->backdropPath;
        }
        $runtime = $forceUpdate || $movie->getRuntime() === null ? $data->runtime : null;
        $rating = $forceUpdate || $movie->getRating() === null ? $data->voteAverage : null;
        $originalLanguage = $forceUpdate || $movie->getOriginalLanguage() === null ? $data->originalLanguage : null;
        $tmdbCollectionId = $forceUpdate || $movie->getTmdbCollectionId() === null ? $data->belongsToCollectionId : null;
        $collectionName = $forceUpdate || $movie->getCollectionName() === null ? $data->belongsToCollectionName : null;

        $movie->updateMetadata(
            overview: $overview,
            tagline: $tagline,
            posterUrl: $posterUrl,
            backdropUrl: $backdropUrl,
            runtime: $runtime,
            rating: $rating,
            originalLanguage: $originalLanguage,
            tmdbCollectionId: $tmdbCollectionId,
            collectionName: $collectionName,
        );

        if ($overview !== null) { $updatedFields[] = 'overview'; }
        if ($tagline !== null) { $updatedFields[] = 'tagline'; }
        if ($posterUrl !== null) { $updatedFields[] = 'posterUrl'; }
        if ($backdropUrl !== null) { $updatedFields[] = 'backdropUrl'; }
        if ($runtime !== null) { $updatedFields[] = 'runtime'; }
        if ($rating !== null) { $updatedFields[] = 'rating'; }
        if ($originalLanguage !== null) { $updatedFields[] = 'originalLanguage'; }
        if ($tmdbCollectionId !== null) { $updatedFields[] = 'tmdbCollectionId'; }
        if ($collectionName !== null) { $updatedFields[] = 'collectionName'; }

        if (!empty($data->genreIds)) {
            $this->syncGenres($movie, $data->genreIds);
            $updatedFields[] = 'genres';
        }

        $this->movieService->save($movie);

        return new EnrichmentResult(
            success: true,
            source: 'tmdb',
            qualityScore: min($data->popularity / 100.0, 1.0),
            updatedFields: $updatedFields,
            identifiersUpdated: $identifiersUpdated,
        );
    }

    /**
     * @param int[] $tmdbGenreIds
     */
    private function syncGenres(Movie $movie, array $tmdbGenreIds): void
    {
        $tmdbGenres = $this->tmdbAdapter->getGenreList();
        $genreMap = [];
        foreach ($tmdbGenres as $genre) {
            $genreMap[$genre->id] = $genre->name;
        }

        foreach ($tmdbGenreIds as $tmdbGenreId) {
            $genreName = $genreMap[$tmdbGenreId] ?? null;
            if ($genreName === null) {
                continue;
            }

            $genre = $this->genreService->findOrCreateByName($genreName);
            $this->genreService->addMovieToGenre($genre->getId(), $movie->getId());
        }
    }
}
