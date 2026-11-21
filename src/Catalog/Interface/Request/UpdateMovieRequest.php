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
            example: 'Updated Movie Title',
            default: null,
        ),
        'year' => new OA\Schema(
            type: 'integer',
            nullable: true,
            example: 2024,
            default: null,
        ),
        'summary' => new OA\Schema(
            type: 'string',
            nullable: true,
            default: null,
        ),
    ],
    component: 'UpdateMovieRequest',
)]
final readonly class UpdateMovieRequest
{
    public function __construct(
        public ?string $title = null,
        public ?int $year = null,
        public ?string $summary = null,
    ) {
    }
}
