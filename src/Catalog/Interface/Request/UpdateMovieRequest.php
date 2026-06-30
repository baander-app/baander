<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Request;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateMovieRequest',
    properties: [
        new OA\Property(property: 'title', type: 'string', example: 'Updated Movie Title', nullable: true),
        new OA\Property(property: 'year', type: 'integer', example: 2024, nullable: true),
        new OA\Property(property: 'summary', type: 'string', nullable: true),
    ],
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
