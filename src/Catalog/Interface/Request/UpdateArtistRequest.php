<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'name' => new OA\Schema(
            type: 'string',
            nullable: true,
            example: 'Updated Artist Name',
            default: null,
        ),
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
        'lockedFields' => new OA\Schema(
            description: 'Full list of locked field names. Replaces the existing list.',
            type: 'array',
            nullable: true,
            items: new OA\Schema(type: 'string'),
            default: null,
        ),
    ],
    component: 'UpdateArtistRequest',
)]
final readonly class UpdateArtistRequest
{
    /**
     * @param string[]|null $lockedFields
     */
    public function __construct(
        public ?string $name = null,
        public ?string $country = null,
        public ?string $gender = null,
        public ?string $type = null,
        public ?string $disambiguation = null,
        public ?string $sortName = null,
        public ?string $biography = null,
        public ?array $lockedFields = null,
    ) {
    }
}
