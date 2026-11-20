<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Song;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SongResource',
    required: ['available'],
    properties: [
        new OA\Property(property: 'publicId', description: 'Public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'albumId', description: 'Album public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', maxLength: 21, minLength: 21),
        new OA\Property(property: 'available', description: 'Whether the latest scan or ingestion observation marked the source available', type: 'boolean'),
        new OA\Property(property: 'title', description: 'Song title', type: 'string'),
        new OA\Property(property: 'artistName', description: 'Primary artist name', type: 'string', nullable: true),
        new OA\Property(property: 'albumName', description: 'Album title', type: 'string', nullable: true),
        new OA\Property(property: 'albumLanguage', description: 'Release language stored on the album, unchanged; usually a language code, but tag readers may store free text', type: 'string', nullable: true),
        new OA\Property(property: 'year', description: 'Release year', type: 'integer', nullable: true),
        new OA\Property(property: 'path', description: 'File path', type: 'string', nullable: true),
        new OA\Property(property: 'length', description: 'Duration in seconds', type: 'number', nullable: true),
        new OA\Property(property: 'track', description: 'Track number', type: 'integer', nullable: true),
        new OA\Property(property: 'disc', description: 'Disc number', type: 'integer', nullable: true),
        new OA\Property(property: 'bitrate', description: 'Bitrate in bps', type: 'integer', nullable: true),
        new OA\Property(property: 'explicit', description: 'Whether the song is explicit', type: 'boolean'),
        new OA\Property(property: 'mbid', description: 'MusicBrainz recording ID', type: 'string', nullable: true),
        new OA\Property(property: 'lockedFields', description: 'Fields locked from automatic updates', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'createdAt', description: 'Creation timestamp', type: 'string', format: 'date-time'),
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
     * @param array<string, bool> $availability songUuid => observed source availability
     * @param array<string, string|null> $albumLanguages albumUuid => stored album language
     * @return array<string, mixed>
     */
    public static function fromWithMeta(Song $song, array $artistNames = [], array $albumTitles = [], array $availability = [], array $albumLanguages = []): array
    {
        $songUuid = $song->getId()->toString();
        $albumUuid = $song->getAlbumId()->toString();

        return [
            'publicId' => $song->getPublicId()->toString(),
            'albumId' => ($song->getAlbumPublicId() ?? throw new \LogicException('Song projection requires the album public identifier.'))->toString(),
            'available' => $availability[$songUuid] ?? false,
            'title' => $song->getTitle(),
            'artistName' => $artistNames[$songUuid] ?? null,
            'albumName' => $albumTitles[$albumUuid] ?? null,
            'albumLanguage' => $albumLanguages[$albumUuid] ?? null,
            'year' => $song->getYear(),
            'path' => $song->getPath(),
            'length' => $song->getLength(),
            'track' => $song->getTrack(),
            'disc' => $song->getDisc(),
            'bitrate' => $song->getBitrate(),
            'explicit' => $song->isExplicit(),
            'mbid' => $song->getMbid(),
            'lockedFields' => $song->getLockedFields(),
            'createdAt' => $song->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Splits album titles and stored languages keyed by album UUID into the title map and the
     * language map the song projection reads.
     *
     * @param array<string, array{title: string, language: string|null}> $albums
     * @return array{array<string, string>, array<string, string|null>}
     */
    public static function albumMaps(array $albums): array
    {
        return [
            array_map(static fn (array $album): string => $album['title'], $albums),
            array_map(static fn (array $album): ?string => $album['language'], $albums),
        ];
    }

    /**
     * @param iterable<Song> $songs
     * @param array<string, string> $artistNames  songUuid => artistName
     * @param array<string, string> $albumTitles  albumUuid => albumTitle
     * @param array<string, bool> $availability songUuid => observed source availability
     * @param array<string, string|null> $albumLanguages albumUuid => stored album language
     * @return list<array<string, mixed>>
     */
    public static function collectionWithMeta(iterable $songs, array $artistNames, array $albumTitles, array $availability = [], array $albumLanguages = []): array
    {
        $result = [];
        foreach ($songs as $song) {
            $result[] = self::fromWithMeta($song, $artistNames, $albumTitles, $availability, $albumLanguages);
        }

        return $result;
    }
}
