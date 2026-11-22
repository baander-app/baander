<?php

declare(strict_types=1);

namespace App\Activity\Interface\Resource;

use App\Activity\Application\DTO\ActivityView;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Media\Domain\Model\Image;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    description: 'An activity of the viewer. The fields that name its song, album, artist or movie are null when that item was deleted or lies outside the viewer\'s current library access.',
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            description: 'Public identifier',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'userId' => new OA\Schema(
            description: 'User public ID',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'activityType' => new OA\Schema(description: 'Type of activity', type: 'string'),
        'songAvailable' => new OA\Schema(
            description: 'Whether the latest scan or ingestion observation marked the song source available; false when the song lies outside the viewer\'s current library access',
            type: 'boolean',
        ),
        'songId' => new OA\Schema(
            description: 'Song public ID; null when the song was deleted or lies outside the viewer\'s current library access',
            type: 'string',
            nullable: true,
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'albumId' => new OA\Schema(
            description: 'Album public ID; null when the album was deleted or lies outside the viewer\'s current library access',
            type: 'string',
            nullable: true,
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'artistId' => new OA\Schema(
            description: 'Artist public ID; null when the artist was deleted or the activity names no song, album or movie inside the viewer\'s current library access',
            type: 'string',
            nullable: true,
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'movieId' => new OA\Schema(
            description: 'Movie public ID; null when the movie was deleted or lies outside the viewer\'s current library access',
            type: 'string',
            nullable: true,
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'playCount' => new OA\Schema(description: 'Number of plays', type: 'integer'),
        'love' => new OA\Schema(description: 'Whether the user loves this item', type: 'boolean'),
        'lastPlayedAt' => new OA\Schema(
            description: 'Last play timestamp',
            type: 'string',
            format: 'date-time',
            nullable: true,
        ),
        'lastPlatform' => new OA\Schema(
            description: 'Last platform used',
            type: 'string',
            nullable: true,
        ),
        'lastPlayer' => new OA\Schema(
            description: 'Last player used',
            type: 'string',
            nullable: true,
        ),
        'createdAt' => new OA\Schema(
            description: 'Creation timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    required: ['publicId', 'userId', 'activityType', 'songAvailable', 'playCount', 'love', 'createdAt'],
    component: 'ActivityResource',
)]
final class ActivityResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof ActivityView);
        $view = $source;
        $source = $view->model;

        return [
            'publicId' => $source->getPublicId()->toString(),
            'userId' => $view->userId->toString(),
            'activityType' => $source->getActivityType(),
            'songId' => $view->songId?->toString(),
            'songAvailable' => false,
            'albumId' => $view->albumId?->toString(),
            'artistId' => $view->artistId?->toString(),
            'movieId' => $view->movieId?->toString(),
            'playCount' => $source->getPlayCount(),
            'love' => $source->isLove(),
            'lastPlayedAt' => $source->getLastPlayedAt()?->format(\DateTimeInterface::ATOM),
            'lastPlatform' => $source->getLastPlatform(),
            'lastPlayer' => $source->getLastPlayer(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, Song> $songs   the songs the viewer may read, keyed by song UUID
     * @param array<string, Album> $albums the albums the viewer may read, keyed by album UUID
     * @param array<string, Image> $images keyed by image UUID
     * @param array<string, array<int, array{name: string, role: string|null}>> $albumArtists keyed by album UUID
     * @param array<string, bool> $availability keyed by song UUID
     * @return array<string, mixed>
     */
    public static function fromWithDetails(
        ActivityView $view,
        array $songs,
        array $albums,
        array $images,
        array $albumArtists,
        string $baseUrl = '',
        array $availability = [],
    ): array {
        $data = self::from($view);
        $activity = $view->model;

        $song = $activity->getSongId() !== null ? ($songs[$activity->getSongId()->toString()] ?? null) : null;
        $album = $activity->getAlbumId() !== null ? ($albums[$activity->getAlbumId()->toString()] ?? null) : null;

        // Song info
        if ($song !== null) {
            $data['songAvailable'] = $availability[$song->getId()->toString()] ?? false;
            $data['songTitle'] = $song->getTitle();
            $data['songPublicId'] = $song->getPublicId()->toString();
        } else {
            $data['songTitle'] = null;
            $data['songPublicId'] = null;
        }

        // Album info
        if ($album !== null) {
            $data['albumPublicId'] = $album->getPublicId()->toString();
            $data['albumTitle'] = $album->getTitle();

            $coverImageId = $album->getCoverImageId();
            if ($coverImageId !== null && isset($images[$coverImageId->toString()])) {
                $image = $images[$coverImageId->toString()];
                $data['coverImage'] = [
                    'url' => $baseUrl . '/api/images/' . $image->getPublicId()->toString() . '/file',
                    'blurhash' => $image->getBlurhash(),
                ];
            } else {
                $data['coverImage'] = null;
            }

            // Artist name from album
            $albumIdStr = $album->getId()->toString();
            $artists = $albumArtists[$albumIdStr] ?? [];
            $data['artistName'] = $artists !== [] ? $artists[0]['name'] : null;
        } else {
            $data['albumPublicId'] = null;
            $data['albumTitle'] = null;
            $data['coverImage'] = null;
            $data['artistName'] = null;
        }

        return $data;
    }
}
