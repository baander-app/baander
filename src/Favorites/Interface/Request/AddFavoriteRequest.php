<?php

declare(strict_types=1);

namespace App\Favorites\Interface\Request;

use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Shared\Domain\Model\PublicId;
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
        #[Assert\Regex(pattern: PublicId::REGEX)]
        #[OA\Property(
            property: 'entityPublicId',
            schema: new OA\Schema(
                type: 'string',
                minLength: PublicId::LENGTH,
                maxLength: PublicId::LENGTH,
                pattern: PublicId::SCHEMA_PATTERN,
            ),
        )]
        public string $entityPublicId,
    ) {
    }
}
