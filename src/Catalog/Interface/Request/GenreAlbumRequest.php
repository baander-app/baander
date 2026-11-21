<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'albumId' => new OA\Schema(
            description: 'Album public ID',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
    ],
    required: ['albumId'],
    component: 'GenreAlbumRequest',
)]
final readonly class GenreAlbumRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Album ID is required.')]
        public string $albumId,
    ) {
    }
}
