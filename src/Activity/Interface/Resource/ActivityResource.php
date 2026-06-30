<?php

declare(strict_types=1);

namespace App\Activity\Interface\Resource;

use App\Activity\Domain\Model\MediaActivity;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use App\Media\Domain\Model\Image;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ActivityResource',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'Activity UUID'),
        new OA\Property(property: 'publicId', type: 'string', format: 'uuid', description: 'Public identifier'),
        new OA\Property(property: 'userId', type: 'string', format: 'uuid', description: 'User UUID'),
        new OA\Property(property: 'activityType', type: 'string', description: 'Type of activity'),
        new OA\Property(property: 'songId', type: 'string', format: 'uuid', nullable: true, description: 'Song UUID'),
        new OA\Property(property: 'albumId', type: 'string', format: 'uuid', nullable: true, description: 'Album UUID'),
        new OA\Property(property: 'artistId', type: 'string', format: 'uuid', nullable: true, description: 'Artist UUID'),
        new OA\Property(property: 'movieId', type: 'string', format: 'uuid', nullable: true, description: 'Movie UUID'),
        new OA\Property(property: 'playCount', type: 'integer', description: 'Number of plays'),
        new OA\Property(property: 'love', type: 'boolean', description: 'Whether the user loves this item'),
        new OA\Property(property: 'lastPlayedAt', type: 'string', format: 'date-time', nullable: true, description: 'Last play timestamp'),
        new OA\Property(property: 'lastPlatform', type: 'string', nullable: true, description: 'Last platform used'),
        new OA\Property(property: 'lastPlayer', type: 'string', nullable: true, description: 'Last player used'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
    ],
)]
final class ActivityResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof MediaActivity);

        return [
            'uuid' => $source->getId()->toString(),
            'publicId' => $source->getPublicId()->toString(),
            'userId' => $source->getUserId()->toString(),
            'activityType' => $source->getActivityType(),
            'songId' => $source->getSongId()?->toString(),
            'albumId' => $source->getAlbumId()?->toString(),
            'artistId' => $source->getArtistId()?->toString(),
            'movieId' => $source->getMovieId()?->toString(),
            'playCount' => $source->getPlayCount(),
            'love' => $source->isLove(),
            'lastPlayedAt' => $source->getLastPlayedAt()?->format(\DateTimeInterface::ATOM),
            'lastPlatform' => $source->getLastPlatform(),
            'lastPlayer' => $source->getLastPlayer(),
            'createdAt' => $source->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, Song> $songs   keyed by song UUID
     * @param array<string, Album> $albums keyed by album UUID
     * @param array<string, Image> $images keyed by image UUID
     * @param array<string, array<int, array{name: string, role: string|null}>> $albumArtists keyed by album UUID
     * @return array<string, mixed>
     */
    public static function fromWithDetails(
        MediaActivity $activity,
        array $songs,
        array $albums,
        array $images,
        array $albumArtists,
        string $baseUrl = '',
    ): array {
        $data = self::from($activity);

        $song = $activity->getSongId() !== null ? ($songs[$activity->getSongId()->toString()] ?? null) : null;
        $album = $activity->getAlbumId() !== null ? ($albums[$activity->getAlbumId()->toString()] ?? null) : null;

        // Song info
        if ($song !== null) {
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
