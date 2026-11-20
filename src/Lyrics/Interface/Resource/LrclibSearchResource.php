<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Resource;

use App\Lyrics\Application\DTO\LrclibSearchResult;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LrclibSearchResource',
    properties: [
        new OA\Property(property: 'id', description: 'LRCLIB track ID', type: 'integer'),
        new OA\Property(property: 'trackName', description: 'Track name', type: 'string', nullable: true),
        new OA\Property(property: 'artistName', description: 'Artist name', type: 'string', nullable: true),
        new OA\Property(property: 'albumName', description: 'Album name', type: 'string', nullable: true),
        new OA\Property(property: 'duration', description: 'Track duration in seconds', type: 'number', nullable: true),
        new OA\Property(property: 'instrumental', description: 'Whether the track is instrumental', type: 'boolean', nullable: true),
        new OA\Property(property: 'plainLyrics', description: 'Plain text lyrics', type: 'string', nullable: true),
        new OA\Property(property: 'syncedLyrics', description: 'Synced (LRC) lyrics', type: 'string', nullable: true),
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
