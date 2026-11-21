<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Song;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'publicId' => new OA\Schema(
            description: 'Public identifier',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'albumId' => new OA\Schema(
            description: 'Album public identifier',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'available' => new OA\Schema(
            description: 'Whether the latest scan or ingestion observation marked the source available',
            type: 'boolean',
        ),
        'title' => new OA\Schema(description: 'Song title', type: 'string'),
        'artistName' => new OA\Schema(
            description: 'Primary artist name',
            type: 'string',
            nullable: true,
        ),
        'albumName' => new OA\Schema(
            description: 'Album title',
            type: 'string',
            nullable: true,
        ),
        'albumLanguage' => new OA\Schema(
            description: 'Release language stored on the album, unchanged; usually a language code, but tag readers may store free text',
            type: 'string',
            nullable: true,
        ),
        'year' => new OA\Schema(
            description: 'Release year',
            type: 'integer',
            nullable: true,
        ),
        'path' => new OA\Schema(
            description: 'File path',
            type: 'string',
            nullable: true,
        ),
        'length' => new OA\Schema(
            description: 'Duration in seconds',
            type: 'number',
            nullable: true,
        ),
        'track' => new OA\Schema(
            description: 'Track number',
            type: 'integer',
            nullable: true,
        ),
        'disc' => new OA\Schema(
            description: 'Disc number',
            type: 'integer',
            nullable: true,
        ),
        'bitrate' => new OA\Schema(
            description: 'Bitrate in bps',
            type: 'integer',
            nullable: true,
        ),
        'explicit' => new OA\Schema(description: 'Whether the song is explicit', type: 'boolean'),
        'mbid' => new OA\Schema(
            description: 'MusicBrainz recording ID',
            type: 'string',
            nullable: true,
        ),
        'lockedFields' => new OA\Schema(
            description: 'Fields locked from automatic updates',
            type: 'array',
            items: new OA\Schema(type: 'string'),
        ),
        'createdAt' => new OA\Schema(
            description: 'Creation timestamp',
            type: 'string',
            format: 'date-time',
        ),
    ],
    required: ['publicId', 'albumId', 'available', 'title', 'explicit', 'lockedFields', 'createdAt'],
    component: 'SongResource',
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
