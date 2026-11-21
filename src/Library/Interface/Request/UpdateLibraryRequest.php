<?php

declare(strict_types=1);

namespace App\Library\Interface\Request;

use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'name' => new OA\Schema(
            type: 'string',
            nullable: true,
            example: 'Updated Library Name',
            default: null,
        ),
        'sortOrder' => new OA\Schema(
            type: 'integer',
            nullable: true,
            example: 1,
            default: null,
        ),
    ],
    component: 'UpdateLibraryRequest',
)]
final readonly class UpdateLibraryRequest
{
    public function __construct(
        public ?string $name = null,
        public ?int $sortOrder = null,
    ) {
    }
}
