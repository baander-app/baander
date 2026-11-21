<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Shared\Domain\Model\PublicId;
use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'albumId' => new OA\Schema(
            description: 'Album public identifier',
            type: 'string',
            minLength: PublicId::LENGTH,
            maxLength: PublicId::LENGTH,
            pattern: PublicId::SCHEMA_PATTERN,
        ),
        'role' => new OA\Schema(
            description: 'Artist role (primary, featured, producer, composer, conductor, remixer, djmix, other)',
            type: 'string',
            enum: [ArtistRole::class],
        ),
    ],
    required: ['albumId', 'role'],
    component: 'ArtistAlbumRequest',
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
