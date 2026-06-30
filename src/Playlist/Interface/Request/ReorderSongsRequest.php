<?php

declare(strict_types=1);

namespace App\Playlist\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Count;

#[OA\Schema(
    schema: 'ReorderSongsRequest',
    required: ['songIds'],
    properties: [
        new OA\Property(property: 'songIds', description: 'Ordered array of song public IDs', type: 'array', items: new OA\Items(type: 'string'), example: ['song-id-1', 'song-id-2', 'song-id-3']),
    ],
)]
final readonly class ReorderSongsRequest
{
    public function __construct(
        #[NotBlank(message: 'Song IDs are required.')]
        #[Count(min: 1, minMessage: 'The "song_ids" field must not be empty.')]
        public array $songIds = [],
    ) {
    }
}
