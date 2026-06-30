<?php

declare(strict_types=1);

namespace App\Lyrics\Interface\Resource;

use App\Lyrics\Domain\Model\Lyrics;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LyricsResource',
    properties: [
        new OA\Property(property: 'plainLyrics', type: 'string', nullable: true, description: 'Plain text lyrics'),
        new OA\Property(property: 'syncedLyrics', type: 'string', nullable: true, description: 'Synced (LRC) lyrics'),
        new OA\Property(property: 'source', type: 'string', nullable: true, description: 'Lyrics source'),
        new OA\Property(property: 'isInstrumental', type: 'boolean', description: 'Whether the track is instrumental'),
    ],
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
