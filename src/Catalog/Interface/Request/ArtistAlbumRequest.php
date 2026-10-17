<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'ArtistAlbumRequest',
    required: ['albumId', 'role'],
    properties: [
        new OA\Property(property: 'albumId', description: 'Album public identifier', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', minLength: 21, maxLength: 21),
        new OA\Property(property: 'role', description: 'Artist role (primary, featured, producer, composer, conductor, remixer, djmix, other)', type: 'string'),
    ],
)]
final readonly class ArtistAlbumRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Album ID is required.')]
        public string $albumId,
        #[Assert\NotBlank(message: 'Role is required.')]
        public string $role,
    ) {
    }
}
