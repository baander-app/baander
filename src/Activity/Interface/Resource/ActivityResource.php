<?php

declare(strict_types=1);

namespace App\Activity\Interface\Resource;

use App\Activity\Application\DTO\ActivityView;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Media\Domain\Model\Image;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ActivityResource',
    required: ['songAvailable'],
    properties: [
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'userId', description: 'User public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'activityType', description: 'Type of activity', type: 'string'),
        new OA\Property(property: 'songAvailable', description: 'Whether the latest scan or ingestion observation marked the song source available', type: 'boolean'),
        new OA\Property(property: 'songId', description: 'Song public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', nullable: true, maxLength: 21, minLength: 21),
        new OA\Property(property: 'albumId', description: 'Album public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', nullable: true, maxLength: 21, minLength: 21),
        new OA\Property(property: 'artistId', description: 'Artist public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', nullable: true, maxLength: 21, minLength: 21),
        new OA\Property(property: 'movieId', description: 'Movie public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', nullable: true, maxLength: 21, minLength: 21),
        new OA\Property(property: 'playCount', description: 'Number of plays', type: 'integer'),
        new OA\Property(property: 'love', description: 'Whether the user loves this item', type: 'boolean'),
        new OA\Property(property: 'lastPlayedAt', description: 'Last play timestamp', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'lastPlatform', description: 'Last platform used', type: 'string', nullable: true),
        new OA\Property(property: 'lastPlayer', description: 'Last player used', type: 'string', nullable: true),
        new OA\Property(property: 'createdAt', description: 'Creation timestamp', type: 'string', format: 'date-time'),
    ],
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
     * @param array<string, Song> $songs   keyed by song public ID
     * @param array<string, Album> $albums keyed by album public ID
     * @param array<string, Image> $images keyed by image public ID
     * @param array<string, array<int, array{name: string, role: string|null}>> $albumArtists keyed by album public ID
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
