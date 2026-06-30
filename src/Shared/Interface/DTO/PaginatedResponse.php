<?php

declare(strict_types=1);

namespace App\Shared\Interface\DTO;

final readonly class PaginatedResponse
{
    /**
     * @param array<int, mixed> $data
     */
    public function __construct(
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
