<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure;

use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Interface\Resource\ActivityResource;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Media\Application\Port\ImagePortInterface;

/**
 * Shared service for enriching activity items with song titles, album covers, and artist names.
 */
final class ActivityEnrichmentService
{
    public function __construct(
        private readonly SongPortInterface $songService,
        private readonly AlbumPortInterface $albumService,
        private readonly ImagePortInterface $imageService,
        private readonly MoviePortInterface $movieService,
    ) {
    }

    /**
     * Batch-resolve song titles, album covers, and artist names for a list of activities.
     *
     * @param MediaActivity[] $activities
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $activities, string $baseUrl): array
    {
        // Collect all referenced song and album UUIDs
        $songIds = [];
        $albumIds = [];

        foreach ($activities as $activity) {
            if ($activity->getSongId() !== null) {
                $songIds[$activity->getSongId()->toString()] = $activity->getSongId();
            }
            if ($activity->getAlbumId() !== null) {
                $albumIds[$activity->getAlbumId()->toString()] = $activity->getAlbumId();
            }
        }

        // Batch-resolve songs
        $songs = $songIds !== [] ? $this->songService->findByUuids(array_values($songIds)) : [];

        // If no album IDs on activities, try to resolve from songs
        foreach ($songs as $song) {
            $albumUuid = $song->getAlbumId();
            if (!isset($albumIds[$albumUuid->toString()])) {
                $albumIds[$albumUuid->toString()] = $albumUuid;
            }
        }

        // Batch-resolve albums
        $albums = $albumIds !== [] ? $this->albumService->findByUuids(array_values($albumIds)) : [];

        // Batch-resolve cover images
        $coverImageIds = [];
        foreach ($albums as $album) {
            $coverImageId = $album->getCoverImageId();
            if ($coverImageId !== null) {
                $coverImageIds[$coverImageId->toString()] = $coverImageId;
            }
        }
        $images = $coverImageIds !== [] ? $this->imageService->findByUuids(array_values($coverImageIds)) : [];

        // Batch-resolve artist names for all albums
        $albumArtists = $albumIds !== []
            ? $this->albumService->getArtistNamesForAlbums(array_values($albumIds))
            : [];

        // Batch-resolve movie titles for activities with movieId
        $movieIds = [];
        foreach ($activities as $activity) {
            if ($activity->getMovieId() !== null) {
                $movieIds[$activity->getMovieId()->toString()] = $activity->getMovieId();
            }
        }
        $movies = [];
        foreach ($movieIds as $movieId) {
            try {
                $movie = $this->movieService->findByUuid($movieId);
                if ($movie !== null) {
                    $movies[$movie->getId()->toString()] = $movie;
                }
            } catch (\Throwable $e) {
                // Ignore — movie may have been deleted
            }
        }

        // Build enriched response
        $enriched = array_map(
            fn(MediaActivity $activity) => ActivityResource::fromWithDetails(
                $activity,
                $songs,
                $albums,
                $images,
                $albumArtists,
                $baseUrl,
            ),
            $activities,
        );

        // Add movie title enrichment
        foreach ($enriched as $idx => $item) {
            $activity = $activities[$idx] ?? null;
            if ($activity?->getMovieId() !== null) {
                $movie = $movies[$activity->getMovieId()->toString()] ?? null;
                if ($movie !== null) {
                    $enriched[$idx]['movieTitle'] = $movie->getTitle();
                    $enriched[$idx]['moviePublicId'] = $movie->getPublicId()->toString();
                    $enriched[$idx]['posterImage'] = $movie->getPosterUrl() !== null
                        ? ['url' => $movie->getPosterUrl()]
                        : null;
                }
            }
        }

        return $enriched;
    }
}
