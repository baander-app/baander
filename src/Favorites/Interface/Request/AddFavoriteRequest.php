<?php

declare(strict_types=1);

namespace App\Favorites\Interface\Request;

use App\Favorites\Domain\ValueObject\FavoriteType;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(schema: 'AddFavoriteRequest')]
final readonly class AddFavoriteRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: [FavoriteType::Song->value, FavoriteType::Album->value, FavoriteType::Artist->value])]
        #[OA\Property(property: 'entityType', type: 'string', enum: ['song', 'album', 'artist'])]
        public string $entityType,

        #[Assert\NotBlank]
        #[OA\Property(property: 'entityPublicId', type: 'string')]
        public string $entityPublicId,
    ) {
    }
}
