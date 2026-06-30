<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    schema: 'CreateArtistRequest',
    required: ['name'],
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Radiohead'),
        new OA\Property(property: 'country', type: 'string', nullable: true),
        new OA\Property(property: 'gender', type: 'string', nullable: true),
        new OA\Property(property: 'type', description: 'Artist type (e.g. person, group)', type: 'string', nullable: true),
        new OA\Property(property: 'disambiguation', type: 'string', nullable: true),
        new OA\Property(property: 'sortName', type: 'string', nullable: true),
        new OA\Property(property: 'biography', type: 'string', nullable: true),
    ],
)]
final readonly class CreateArtistRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Artist name is required.')]
        public string $name,
        public ?string $country = null,
        public ?string $gender = null,
        public ?string $type = null,
        public ?string $disambiguation = null,
        public ?string $sortName = null,
        public ?string $biography = null,
    ) {
    }
}
