<?php

declare(strict_types=1);

namespace App\Favorites\Interface\Request;

use App\Favorites\Domain\ValueObject\FavoriteType;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [],
    component: 'AddFavoriteRequest',
)]
final readonly class AddFavoriteRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: [FavoriteType::Song->value, FavoriteType::Album->value, FavoriteType::Artist->value])]
        #[OA\Property(
            property: 'entityType',
            schema: new OA\Schema(
                type: 'string',
                enum: ['song', 'album', 'artist'],
            ),
        )]
        public string $entityType,

        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/^[A-Za-z0-9_-]{21}$/D')]
        #[OA\Property(
            property: 'entityPublicId',
            schema: new OA\Schema(
                type: 'string',
                minLength: 21,
                maxLength: 21,
                pattern: '^[A-Za-z0-9_-]{21}$',
            ),
        )]
        public string $entityPublicId,
    ) {
    }
}
