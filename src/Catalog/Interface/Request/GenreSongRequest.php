<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'songId' => new OA\Schema(
            description: 'Song public ID',
            type: 'string',
            minLength: 21,
            maxLength: 21,
            pattern: '^[0-9a-zA-Z_-]{21}$',
        ),
    ],
    required: ['songId'],
    component: 'GenreSongRequest',
)]
final readonly class GenreSongRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Song ID is required.')]
        public string $songId,
    ) {
    }
}
