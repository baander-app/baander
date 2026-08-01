<?php

declare(strict_types=1);

namespace App\Activity\Interface\Request;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PlayActivityRequest',
    required: [],
    properties: [
        new OA\Property(property: 'songId', description: 'Public ID of the song being played', type: 'string', nullable: true),
        new OA\Property(property: 'albumId', description: 'Public ID of the album', type: 'string', nullable: true),
        new OA\Property(property: 'artistId', description: 'Public ID of the artist', type: 'string', nullable: true),
        new OA\Property(property: 'movieId', description: 'Public ID of the movie', type: 'string', nullable: true),
        new OA\Property(property: 'platform', description: 'Playback platform (e.g. web, mobile, desktop)', type: 'string', nullable: true),
        new OA\Property(property: 'player', description: 'Player application name', type: 'string', nullable: true),
    ],
)]
final readonly class PlayActivityRequest
{
    public function __construct(
        public ?string $songId = null,
        public ?string $albumId = null,
        public ?string $artistId = null,
        public ?string $movieId = null,
        public ?string $platform = null,
        public ?string $player = null,
    ) {
    }
}
