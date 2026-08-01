<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Song;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SongResource',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', description: 'Song UUID'),
        new OA\Property(property: 'publicId', type: 'string', format: 'uuid', description: 'Public-facing UUID'),
        new OA\Property(property: 'albumId', type: 'string', format: 'uuid', description: 'Album UUID'),
        new OA\Property(property: 'title', type: 'string', description: 'Song title'),
        new OA\Property(property: 'artistName', type: 'string', nullable: true, description: 'Primary artist name'),
        new OA\Property(property: 'albumName', type: 'string', nullable: true, description: 'Album title'),
        new OA\Property(property: 'year', type: 'integer', nullable: true, description: 'Release year'),
        new OA\Property(property: 'path', type: 'string', nullable: true, description: 'File path'),
        new OA\Property(property: 'length', type: 'number', nullable: true, description: 'Duration in seconds'),
        new OA\Property(property: 'track', type: 'integer', nullable: true, description: 'Track number'),
        new OA\Property(property: 'disc', type: 'integer', nullable: true, description: 'Disc number'),
        new OA\Property(property: 'bitrate', type: 'integer', nullable: true, description: 'Bitrate in bps'),
        new OA\Property(property: 'explicit', type: 'boolean', description: 'Whether the song is explicit'),
        new OA\Property(property: 'lockedFields', type: 'array', items: new OA\Items(type: 'string'), description: 'Fields locked from automatic updates'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', description: 'Creation timestamp'),
    ],
)]
final class SongResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        return self::fromWithMeta($source);
    }

    /**
     * @param array<string, string> $artistNames  songUuid => artistName
     * @param array<string, string> $albumTitles  albumUuid => albumTitle
     */
    public static function fromWithMeta(Song $song, array $artistNames = [], array $albumTitles = []): array
    {
        $songUuid = $song->getId()->toString();
        $albumUuid = $song->getAlbumId()->toString();

        return [
            'uuid' => $songUuid,
            'publicId' => $song->getPublicId()->toString(),
            'albumId' => $song->getAlbumPublicId()?->toString() ?? $albumUuid,
            'title' => $song->getTitle(),
            'artistName' => $artistNames[$songUuid] ?? null,
            'albumName' => $albumTitles[$albumUuid] ?? null,
            'year' => $song->getYear(),
            'path' => $song->getPath(),
            'length' => $song->getLength(),
            'track' => $song->getTrack(),
            'disc' => $song->getDisc(),
            'bitrate' => $song->getBitrate(),
            'explicit' => $song->isExplicit(),
            'lockedFields' => $song->getLockedFields(),
            'createdAt' => $song->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param iterable<Song> $songs
     * @param array<string, string> $artistNames  songUuid => artistName
     * @param array<string, string> $albumTitles  albumUuid => albumTitle
     */
    public static function collectionWithMeta(iterable $songs, array $artistNames, array $albumTitles): array
    {
        $result = [];
        foreach ($songs as $song) {
            $result[] = self::fromWithMeta($song, $artistNames, $albumTitles);
        }

        return $result;
    }
}
