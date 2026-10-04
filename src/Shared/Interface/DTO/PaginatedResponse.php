<?php

declare(strict_types=1);

namespace App\Shared\Interface\DTO;

use Nelmio\ApiDocBundle\Attribute\Ignore;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PaginatedResponse',
    required: ['data', 'meta'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(nullable: true)),
        new OA\Property(property: 'meta', required: ['current_page', 'last_page', 'per_page', 'total'], properties: [
            new OA\Property(property: 'current_page', type: 'integer'),
            new OA\Property(property: 'last_page', type: 'integer'),
            new OA\Property(property: 'per_page', type: 'integer'),
            new OA\Property(property: 'total', type: 'integer'),
        ], type: 'object'),
    ],
)]
final readonly class PaginatedResponse
{
    /**
     * @param array<int, mixed> $data
     */
    public function __construct(
        // Document the toArray() envelope rather than these constructor fields.
        #[Ignore]
        public array $data,
        #[Ignore]
        public int $currentPage,
        #[Ignore]
        public int $lastPage,
        #[Ignore]
        public int $perPage,
        #[Ignore]
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
