<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'GenreSongRequest',
    required: ['songId'],
    properties: [
        new OA\Property(property: 'songId', description: 'Song public ID', type: 'string', pattern: '^[0-9a-zA-Z_-]{21}$', minLength: 21, maxLength: 21),
    ],
)]
final readonly class GenreSongRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Song ID is required.')]
        public string $songId,
    ) {
    }
}
