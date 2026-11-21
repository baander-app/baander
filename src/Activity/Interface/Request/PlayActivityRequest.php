<?php

declare(strict_types=1);

namespace App\Activity\Interface\Request;

use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'songId' => new OA\Schema(
            description: 'Public ID of the song being played',
            type: 'string',
            nullable: true,
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
            default: null,
        ),
        'albumId' => new OA\Schema(
            description: 'Public ID of the album',
            type: 'string',
            nullable: true,
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
            default: null,
        ),
        'artistId' => new OA\Schema(
            description: 'Public ID of the artist',
            type: 'string',
            nullable: true,
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
            default: null,
        ),
        'movieId' => new OA\Schema(
            description: 'Public ID of the movie',
            type: 'string',
            nullable: true,
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
            default: null,
        ),
        'platform' => new OA\Schema(
            description: 'Playback platform (e.g. web, mobile, desktop)',
            type: 'string',
            nullable: true,
            default: null,
        ),
        'player' => new OA\Schema(
            description: 'Player application name',
            type: 'string',
            nullable: true,
            default: null,
        ),
    ],
    component: 'PlayActivityRequest',
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
