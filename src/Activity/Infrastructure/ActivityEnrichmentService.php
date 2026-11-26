<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure;

use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Application\DTO\ActivityView;
use App\Activity\Application\Port\ActivityIdentityProjectionPortInterface;
use App\Activity\Interface\Resource\ActivityResource;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Port\SongAvailabilityPortInterface;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Media\Application\Port\ImagePortInterface;
use App\Shared\Domain\Model\Uuid;

/**
 * Shared service for enriching activity items with song titles, album covers, and artist names.
 *
 * Only items inside the viewer's current library access are named. An activity whose song,
 * album or movie lies outside it keeps its own fields, while that item's identifier, title,
 * cover and poster stay null and the song counts as unavailable.
 */
final class ActivityEnrichmentService
{
    public function __construct(
        private readonly SongPortInterface $songService,
        private readonly AlbumPortInterface $albumService,
        private readonly ImagePortInterface $imageService,
        private readonly MoviePortInterface $movieService,
        private readonly ActivityIdentityProjectionPortInterface $identities,
        private readonly SongAvailabilityPortInterface $availability,
        private readonly LibraryReadScopeProviderInterface $libraryScopes,
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
        $scope = $this->libraryScopes->current();
        $inScope = static fn (object $item): bool => $scope->allows($item->getLibraryId());

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

        // Batch-resolve albums, keeping those in the viewer's libraries and the songs on them
        $albums = $albumIds !== []
            ? array_filter($albumIds |> array_values(...) |> $this->albumService->findByUuids(...), $inScope)
            : [];
        $songs = array_filter(
            $songs,
            static fn (object $song): bool => isset($albums[$song->getAlbumId()->toString()]),
        );

        // Batch-resolve cover images
        $coverImageIds = [];
        foreach ($albums as $album) {
            $coverImageId = $album->getCoverImageId();
            if ($coverImageId !== null) {
                $coverImageIds[$coverImageId->toString()] = $coverImageId;
            }
        }
        $images = $coverImageIds !== [] ? $this->imageService->findByUuids(array_values($coverImageIds)) : [];

        // Batch-resolve artist names for the readable albums
        $albumArtists = $albums !== []
            ? array_map(static fn (object $album): Uuid => $album->getId(), array_values($albums))
                |> $this->albumService->getArtistNamesForAlbums(...)
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
                if ($movie !== null && $inScope($movie)) {
                    $movies[$movie->getId()->toString()] = $movie;
                }
            } catch (\Throwable $e) {
                // Ignore — movie may have been deleted
            }
        }

        $artists = [];
        $userPublicIds = [];
        foreach ($activities as $activity) {
            $userKey = $activity->getUserId()->toString();
            $userPublicIds[$userKey] ??= $this->identities->userPublicId($activity->getUserId());
            $artistId = $activity->getArtistId();
            if ($artistId !== null && !array_key_exists($artistId->toString(), $artists)) {
                $artists[$artistId->toString()] = $this->identities->artistPublicId($artistId);
            }
        }

        $availability = $songs === [] ? [] : $this->availability->forSongs(array_values($songs));

        // Build enriched response
        $enriched = array_map(
            fn(MediaActivity $activity) => ActivityResource::fromWithDetails(
                new ActivityView(
                    $activity,
                    $userPublicIds[$activity->getUserId()->toString()],
                    $activity->getSongId() === null ? null : ($songs[$activity->getSongId()->toString()] ?? null)?->getPublicId(),
                    $activity->getAlbumId() === null ? null : ($albums[$activity->getAlbumId()->toString()] ?? null)?->getPublicId(),
                    $activity->getArtistId() === null || !self::namesReadableItem($activity, $songs, $albums, $movies)
                        ? null
                        : ($artists[$activity->getArtistId()->toString()] ?? null),
                    $activity->getMovieId() === null ? null : ($movies[$activity->getMovieId()->toString()] ?? null)?->getPublicId(),
                ),
                $songs,
                $albums,
                $images,
                $albumArtists,
                $baseUrl,
                $availability,
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

    /**
     * Whether the activity's song, album or movie is one the viewer may read. Its artist is named
     * only alongside such an item, since the artist's own libraries are not resolved here.
     *
     * @param array<string, mixed> $songs  readable songs keyed by UUID
     * @param array<string, mixed> $albums readable albums keyed by UUID
     * @param array<string, mixed> $movies readable movies keyed by UUID
     */
    private static function namesReadableItem(MediaActivity $activity, array $songs, array $albums, array $movies): bool
    {
        return self::isAmong($activity->getSongId(), $songs)
            || self::isAmong($activity->getAlbumId(), $albums)
            || self::isAmong($activity->getMovieId(), $movies);
    }

    /** @param array<string, mixed> $items keyed by UUID */
    private static function isAmong(?Uuid $id, array $items): bool
    {
        return $id !== null && isset($items[$id->toString()]);
    }
}
