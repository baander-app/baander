<?php

declare(strict_types=1);

namespace App\Shared\Interface\DTO;

use App\Shared\Domain\Model\CursorPage;
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
                'next_cursor' => new OA\Schema(type: 'string', nullable: true),
                'prev_cursor' => new OA\Schema(type: 'string', nullable: true),
                'has_next_page' => new OA\Schema(type: 'boolean'),
                'has_previous_page' => new OA\Schema(type: 'boolean'),
                'total' => new OA\Schema(type: 'integer'),
                'per_page' => new OA\Schema(type: 'integer'),
                'stale_cursor' => new OA\Schema(type: 'boolean'),
            ],
            required: [
                'next_cursor',
                'prev_cursor',
                'has_next_page',
                'has_previous_page',
                'total',
                'per_page',
                'stale_cursor',
            ],
        ),
    ],
    required: ['data', 'meta'],
    component: 'CursorPaginatedResponse',
)]
final readonly class CursorPaginatedResponse
{
    /**
     * @param array<int, mixed> $data
     */
    public function __construct(
        // Document the toArray() envelope rather than these constructor fields.
        public array $data,
        public ?string $nextCursor,
        public ?string $prevCursor,
        public bool $hasNextPage,
        public bool $hasPreviousPage,
        public int $total,
        public bool $staleCursor,
        public int $perPage,
    ) {
    }

    /** @param array<int, mixed> $data */
    public static function fromPage(CursorPage $page, array $data): self
    {
        return new self(
            data: $data,
            nextCursor: $page->getNextCursor(),
            prevCursor: $page->getPrevCursor(),
            hasNextPage: $page->hasNextPage(),
            hasPreviousPage: $page->hasPreviousPage(),
            total: $page->getTotal(),
            staleCursor: $page->isStaleCursor(),
            perPage: $page->getPerPage(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'meta' => [
                'next_cursor' => $this->nextCursor,
                'prev_cursor' => $this->prevCursor,
                'has_next_page' => $this->hasNextPage,
                'has_previous_page' => $this->hasPreviousPage,
                'total' => $this->total,
                'per_page' => $this->perPage,
                'stale_cursor' => $this->staleCursor,
            ],
        ];
    }
}
