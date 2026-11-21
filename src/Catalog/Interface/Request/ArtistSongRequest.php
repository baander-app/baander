<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'songId' => new OA\Schema(
            description: 'Song public identifier',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
        'role' => new OA\Schema(
            description: 'Artist role (primary, featured, producer, composer, conductor, remixer, djmix, other)',
            type: 'string',
        ),
    ],
    required: ['songId', 'role'],
    component: 'ArtistSongRequest',
)]
final readonly class ArtistSongRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Song ID is required.')]
        public string $songId,
        #[Assert\NotBlank(message: 'Role is required.')]
        public string $role,
    ) {
    }
}
