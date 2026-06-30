<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateAlbumRequest',
    properties: [
        new OA\Property(property: 'title', type: 'string', example: 'Updated Album Title', nullable: true),
        new OA\Property(property: 'type', description: 'Album type (e.g. album, single, ep, compilation)', type: 'string', nullable: true),
        new OA\Property(property: 'year', type: 'integer', example: 2024, nullable: true),
        new OA\Property(property: 'label', type: 'string', nullable: true),
        new OA\Property(property: 'catalogNumber', type: 'string', nullable: true),
        new OA\Property(property: 'barcode', type: 'string', nullable: true),
        new OA\Property(property: 'country', type: 'string', nullable: true),
        new OA\Property(property: 'language', type: 'string', nullable: true),
        new OA\Property(property: 'disambiguation', type: 'string', nullable: true),
        new OA\Property(property: 'annotation', type: 'string', nullable: true),
        new OA\Property(property: 'lockedFields', description: 'Full list of locked field names. Replaces the existing list.', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
    ],
)]
final readonly class UpdateAlbumRequest
{
    /**
     * @param string[]|null $lockedFields
     */
    public function __construct(
        public ?string $title = null,
        public ?string $type = null,
        public ?int $year = null,
        public ?string $label = null,
        public ?string $catalogNumber = null,
        public ?string $barcode = null,
        public ?string $country = null,
        public ?string $language = null,
        public ?string $disambiguation = null,
        public ?string $annotation = null,
        public ?array $lockedFields = null,
    ) {
    }
}
