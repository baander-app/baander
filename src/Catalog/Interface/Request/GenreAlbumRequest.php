<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'albumId' => new OA\Schema(
            description: 'Album public ID',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
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
