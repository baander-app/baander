<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Resource;

use App\Lyrics\Application\DTO\LrclibSearchResult;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'id' => new OA\Schema(description: 'LRCLIB track ID', type: 'integer'),
        'trackName' => new OA\Schema(
            description: 'Track name',
            type: 'string',
            nullable: true,
        ),
        'artistName' => new OA\Schema(
            description: 'Artist name',
            type: 'string',
            nullable: true,
        ),
        'albumName' => new OA\Schema(
            description: 'Album name',
            type: 'string',
            nullable: true,
        ),
        'duration' => new OA\Schema(
            description: 'Track duration in seconds',
            type: 'number',
            nullable: true,
        ),
        'instrumental' => new OA\Schema(
            description: 'Whether the track is instrumental',
            type: 'boolean',
            nullable: true,
        ),
        'plainLyrics' => new OA\Schema(
            description: 'Plain text lyrics',
            type: 'string',
            nullable: true,
        ),
        'syncedLyrics' => new OA\Schema(
            description: 'Synced (LRC) lyrics',
            type: 'string',
            nullable: true,
        ),
    ],
    required: ['id'],
    component: 'LrclibSearchResource',
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
