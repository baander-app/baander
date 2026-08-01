<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateArtistRequest',
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Updated Artist Name', nullable: true),
        new OA\Property(property: 'country', type: 'string', nullable: true),
        new OA\Property(property: 'gender', type: 'string', nullable: true),
        new OA\Property(property: 'type', description: 'Artist type (e.g. person, group)', type: 'string', nullable: true),
        new OA\Property(property: 'disambiguation', type: 'string', nullable: true),
        new OA\Property(property: 'sortName', type: 'string', nullable: true),
        new OA\Property(property: 'biography', type: 'string', nullable: true),
        new OA\Property(property: 'lockedFields', description: 'Full list of locked field names. Replaces the existing list.', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
    ],
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
