<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Resource;

use App\Lyrics\Application\DTO\LrclibSearchResult;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LrclibSearchResource',
    properties: [
        new OA\Property(property: 'id', type: 'integer', description: 'LRCLIB track ID'),
        new OA\Property(property: 'trackName', type: 'string', nullable: true, description: 'Track name'),
        new OA\Property(property: 'artistName', type: 'string', nullable: true, description: 'Artist name'),
        new OA\Property(property: 'albumName', type: 'string', nullable: true, description: 'Album name'),
        new OA\Property(property: 'duration', type: 'number', nullable: true, description: 'Track duration in seconds'),
        new OA\Property(property: 'instrumental', type: 'boolean', nullable: true, description: 'Whether the track is instrumental'),
        new OA\Property(property: 'plainLyrics', type: 'string', nullable: true, description: 'Plain text lyrics'),
        new OA\Property(property: 'syncedLyrics', type: 'string', nullable: true, description: 'Synced (LRC) lyrics'),
    ],
)]
final class LrclibSearchResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof LrclibSearchResult);

        return [
            'id' => $source->id,
            'trackName' => $source->trackName,
            'artistName' => $source->artistName,
            'albumName' => $source->albumName,
            'duration' => $source->duration,
            'instrumental' => $source->instrumental,
            'plainLyrics' => $source->plainLyrics,
            'syncedLyrics' => $source->syncedLyrics,
        ];
    }
}
