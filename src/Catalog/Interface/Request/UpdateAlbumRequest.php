<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'title' => new OA\Schema(
            type: 'string',
            nullable: true,
            example: 'Updated Album Title',
            default: null,
        ),
        'type' => new OA\Schema(
            description: 'Album type (e.g. album, single, ep, compilation)',
            type: 'string',
            nullable: true,
            default: null,
        ),
        'year' => new OA\Schema(
            type: 'integer',
            nullable: true,
            example: 2024,
            default: null,
        ),
        'label' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'catalogNumber' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'barcode' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'country' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'language' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'disambiguation' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
        'annotation' => new OA\Schema(
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
    component: 'UpdateAlbumRequest',
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
