<?php

declare(strict_types=1);

namespace App\Library\Interface\Request;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateLibraryRequest',
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Updated Library Name', nullable: true),
        new OA\Property(property: 'sortOrder', type: 'integer', example: 1, nullable: true),
    ],
)]
final readonly class UpdateLibraryRequest
{
    public function __construct(
        public ?string $name = null,
        public ?int $sortOrder = null,
    ) {
    }
}
