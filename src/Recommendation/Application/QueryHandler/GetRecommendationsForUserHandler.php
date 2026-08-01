<?php

declare(strict_types=1);

namespace App\Recommendation\Application\QueryHandler;

use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\Repository\ArtistRepositoryInterface;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Media\Application\Port\ImagePortInterface;
use App\Recommendation\Application\Query\GetRecommendationsForUserQuery;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use App\Recommendation\Interface\Resource\RecommendationResource;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\HttpFoundation\RequestStack;

final class GetRecommendationsForUserHandler
{
    public function __construct(
        private readonly RecommendationRepositoryInterface $recommendationRepository,
        private readonly AlbumRepositoryInterface $albumRepository,
        private readonly ArtistRepositoryInterface $artistRepository,
        private readonly SongRepositoryInterface $songRepository,
        private readonly ImagePortInterface $imagePort,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(GetRecommendationsForUserQuery $query): array
    {
        $recommendations = $this->recommendationRepository->findForUser(
            $query->getUserId(),
            $query->getLimit(),
        );

        if ($recommendations === []) {
            return [];
        }

        // Separate target and source IDs by type
        $albumIds = [];
        $artistIds = [];
        $songIds = [];
        $coverImageIds = [];
        $sourceAlbumIds = [];
        $sourceArtistIds = [];
        $sourceSongIds = [];

        foreach ($recommendations as $rec) {
            $targetType = (string) $rec->getTargetType();
            $targetId = $rec->getTargetId();
            $sourceType = (string) $rec->getSourceType();
            $sourceId = $rec->getSourceId();

            if ($targetType === 'album') {
                $albumIds[] = new Uuid($targetId);
            } elseif ($targetType === 'artist') {
                $artistIds[] = new Uuid($targetId);
            } elseif ($targetType === 'song') {
                $songIds[] = new Uuid($targetId);
            }

            if ($sourceType === 'album') {
                $sourceAlbumIds[] = new Uuid($sourceId);
            } elseif ($sourceType === 'artist') {
                $sourceArtistIds[] = new Uuid($sourceId);
            } elseif ($sourceType === 'song') {
                $sourceSongIds[] = new Uuid($sourceId);
            }
        }

        // Bulk fetch target entities
        $albums = $albumIds !== [] ? $this->albumRepository->findByUuids($albumIds) : [];
        $artists = $artistIds !== [] ? $this->artistRepository->findByUuids($artistIds) : [];
        $songs = $songIds !== [] ? $this->songRepository->findByUuids($songIds) : [];

        // Bulk fetch source entities for names
        $sourceAlbums = $sourceAlbumIds !== [] ? $this->albumRepository->findByUuids($sourceAlbumIds) : [];
        $sourceArtists = $sourceArtistIds !== [] ? $this->artistRepository->findByUuids($sourceArtistIds) : [];
        $sourceSongs = $sourceSongIds !== [] ? $this->songRepository->findByUuids($sourceSongIds) : [];

        // Collect cover image IDs and album IDs from songs
        $songAlbumIds = [];
        foreach ($songs as $song) {
            $albumId = $song->getAlbumId();
            $songAlbumIds[] = $albumId;
        }

        // Fetch albums for songs (for cover art and artist names)
        $songAlbums = $songAlbumIds !== [] ? $this->albumRepository->findByUuids($songAlbumIds) : [];

        // Collect cover image IDs
        foreach ($albums as $album) {
            $coverId = $album->getCoverImageId();
            if ($coverId !== null) {
                $coverImageIds[] = $coverId;
            }
        }
        foreach ($artists as $artist) {
            $coverId = $artist->getCoverImageId();
            if ($coverId !== null) {
                $coverImageIds[] = $coverId;
            }
        }
        foreach ($songAlbums as $album) {
            $coverId = $album->getCoverImageId();
            if ($coverId !== null) {
                $coverImageIds[] = $coverId;
            }
        }

        // Bulk fetch cover images
        $images = $coverImageIds !== [] ? $this->imagePort->findByUuids($coverImageIds) : [];

        // Get artist names for albums (including song albums)
        $allAlbumIds = [...$albumIds, ...$songAlbumIds];
        $albumArtistNames = $allAlbumIds !== [] ? $this->albumRepository->getArtistNamesForAlbums($allAlbumIds) : [];

        // Build image URL map
        $request = $this->requestStack->getCurrentRequest();
        $baseUrl = $request !== null ? $request->getSchemeAndHttpHost() : '';

        $imageUrlMap = [];
        foreach ($images as $image) {
            $imageUrlMap[$image->getId()->toString()] = $baseUrl . '/api/images/' . $image->getPublicId()->toString() . '/file';
        }

        // Enrich recommendations
        $enriched = [];
        foreach ($recommendations as $rec) {
            $resource = RecommendationResource::from($rec);
            $targetId = $rec->getTargetId();
            $targetType = (string) $rec->getTargetType();
            $sourceId = $rec->getSourceId();
            $sourceType = (string) $rec->getSourceType();

            // Enrich source name
            if ($sourceType === 'album') {
                $sourceAlbum = $sourceAlbums[$sourceId] ?? null;
                $resource['sourceName'] = $sourceAlbum?->getTitle();
            } elseif ($sourceType === 'artist') {
                $sourceArtist = $sourceArtists[$sourceId] ?? null;
                $resource['sourceName'] = $sourceArtist?->getName();
            } elseif ($sourceType === 'song') {
                $sourceSong = $sourceSongs[$sourceId] ?? null;
                $resource['sourceName'] = $sourceSong?->getTitle();
            } else {
                $resource['sourceName'] = null;
            }

            if ($targetType === 'album') {
                $album = $albums[$targetId] ?? null;
                if ($album !== null) {
                    $resource['targetTitle'] = $album->getTitle();

                    $artistNames = $albumArtistNames[$targetId] ?? [];
                    $resource['targetArtistName'] = $artistNames !== [] ? $artistNames[0]['name'] : null;

                    $coverId = $album->getCoverImageId();
                    $resource['coverImageUrl'] = $coverId !== null && isset($imageUrlMap[$coverId->toString()])
                        ? $imageUrlMap[$coverId->toString()]
                        : null;
                } else {
                    $resource['targetTitle'] = null;
                    $resource['targetArtistName'] = null;
                    $resource['coverImageUrl'] = null;
                }
            } elseif ($targetType === 'artist') {
                $artist = $artists[$targetId] ?? null;
                if ($artist !== null) {
                    $resource['targetTitle'] = $artist->getName();
                    $resource['targetArtistName'] = null;

                    $coverId = $artist->getCoverImageId();
                    $resource['coverImageUrl'] = $coverId !== null && isset($imageUrlMap[$coverId->toString()])
                        ? $imageUrlMap[$coverId->toString()]
                        : null;
                } else {
                    $resource['targetTitle'] = null;
                    $resource['targetArtistName'] = null;
                    $resource['coverImageUrl'] = null;
                }
            } elseif ($targetType === 'song') {
                $song = $songs[$targetId] ?? null;
                if ($song !== null) {
                    $resource['targetTitle'] = $song->getTitle();

                    // Get artist and cover from the song's album
                    $albumId = $song->getAlbumId()->toString();
                    $songAlbum = $songAlbums[$albumId] ?? null;

                    if ($songAlbum !== null) {
                        $artistNames = $albumArtistNames[$albumId] ?? [];
                        $resource['targetArtistName'] = $artistNames !== [] ? $artistNames[0]['name'] : null;

                        $coverId = $songAlbum->getCoverImageId();
                        $resource['coverImageUrl'] = $coverId !== null && isset($imageUrlMap[$coverId->toString()])
                            ? $imageUrlMap[$coverId->toString()]
                            : null;
                    } else {
                        $resource['targetArtistName'] = null;
                        $resource['coverImageUrl'] = null;
                    }
                } else {
                    $resource['targetTitle'] = null;
                    $resource['targetArtistName'] = null;
                    $resource['coverImageUrl'] = null;
                }
            } else {
                $resource['targetTitle'] = null;
                $resource['targetArtistName'] = null;
                $resource['coverImageUrl'] = null;
            }

            $enriched[] = $resource;
        }

        return $enriched;
    }
}
