<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Spec as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(
    type: 'object',
    properties: [
        'name' => new OA\Schema(type: 'string', example: 'Radiohead'),
        'country' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'gender' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'type' => new OA\Schema(
            description: 'Artist type (e.g. person, group)',
            type: 'string',
            nullable: true,
            default: null,
        ),
        'disambiguation' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'sortName' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'biography' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
    ],
    required: ['name'],
    component: 'CreateArtistRequest',
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
