<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'ArtistSongRequest',
    required: ['songId', 'role'],
    properties: [
        new OA\Property(property: 'songId', description: 'Song UUID', type: 'string'),
        new OA\Property(property: 'role', description: 'Artist role (primary, featured, producer, composer, conductor, remixer, djmix, other)', type: 'string'),
    ],
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
