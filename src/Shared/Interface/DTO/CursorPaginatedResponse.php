<?php

declare(strict_types=1);

namespace App\Shared\Interface\DTO;

use App\Shared\Domain\Model\CursorPage;

final readonly class CursorPaginatedResponse
{
    /**
     * @param array<int, mixed> $data
     */
    public function __construct(
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
