<?php

declare(strict_types=1);

namespace App\Shared\Interface\DTO;

use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        'data' => new OA\Schema(
            type: 'array',
            items: new OA\Schema(nullable: true),
        ),
        'meta' => new OA\Schema(
            type: 'object',
            properties: [
                'current_page' => new OA\Schema(type: 'integer'),
                'last_page' => new OA\Schema(type: 'integer'),
                'per_page' => new OA\Schema(type: 'integer'),
                'total' => new OA\Schema(type: 'integer'),
            ],
            required: ['current_page', 'last_page', 'per_page', 'total'],
        ),
    ],
    required: ['data', 'meta'],
    component: 'PaginatedResponse',
)]
final readonly class PaginatedResponse
{
    /**
     * @param array<int, mixed> $data
     */
    public function __construct(
        // Document the toArray() envelope rather than these constructor fields.
        public array $data,
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'meta' => [
                'current_page' => $this->currentPage,
                'last_page' => $this->lastPage,
                'per_page' => $this->perPage,
                'total' => $this->total,
            ],
        ];
    }
}
