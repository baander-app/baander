<?php

declare(strict_types=1);

namespace App\Favorites\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(schema: 'AddFavoriteRequest')]
final readonly class AddFavoriteRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[OA\Property(property: 'entityType', type: 'string', enum: ['song', 'album', 'artist'])]
        public string $entityType,

        #[Assert\NotBlank]
        #[OA\Property(property: 'entityPublicId', type: 'string')]
        public string $entityPublicId,
    ) {
    }
}
