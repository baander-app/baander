<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Resource;

use App\Lyrics\Domain\Model\Lyrics;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
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
        'source' => new OA\Schema(
            description: 'Lyrics source',
            type: 'string',
            nullable: true,
        ),
        'isInstrumental' => new OA\Schema(description: 'Whether the track is instrumental', type: 'boolean'),
    ],
    required: ['isInstrumental'],
    component: 'LyricsResource',
)]
final class LyricsResource extends AbstractResource
{
    public static function from(mixed $source): array
    {
        assert($source instanceof Lyrics);

        return [
            'plainLyrics' => $source->getLyrics(),
            'syncedLyrics' => $source->getSyncedLyrics(),
            'source' => $source->getSource(),
            'isInstrumental' => $source->isInstrumental(),
        ];
    }
}
