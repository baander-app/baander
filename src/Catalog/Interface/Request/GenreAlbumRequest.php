<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'GenreAlbumRequest',
    required: ['albumId'],
    properties: [
        new OA\Property(property: 'albumId', description: 'Album UUID', type: 'string'),
    ],
)]
final readonly class GenreAlbumRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Album ID is required.')]
        public string $albumId,
    ) {
    }
}
