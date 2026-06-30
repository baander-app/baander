<?php

declare(strict_types=1);

namespace App\Shared\Domain\Model;

final readonly class CursorPage
{
    /**
     * @param array<mixed> $items
     */
    public function __construct(
        private array $items,
        private ?string $nextCursor,
        private ?string $prevCursor,
        private bool $hasNextPage,
        private bool $hasPreviousPage,
        private int $total,
        private bool $staleCursor,
        private int $perPage,
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getNextCursor(): ?string
    {
        return $this->nextCursor;
    }

    public function getPrevCursor(): ?string
    {
        return $this->prevCursor;
    }

    public function hasNextPage(): bool
    {
        return $this->hasNextPage;
    }

    public function hasPreviousPage(): bool
    {
        return $this->hasPreviousPage;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function isStaleCursor(): bool
    {
        return $this->staleCursor;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * @return array{
     *     items: array<mixed>,
     *     next_cursor: ?string,
     *     prev_cursor: ?string,
     *     has_next_page: bool,
     *     has_previous_page: bool,
     *     total: int,
     *     stale_cursor: bool,
     *     per_page: int,
     * }
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'next_cursor' => $this->nextCursor,
            'prev_cursor' => $this->prevCursor,
            'has_next_page' => $this->hasNextPage,
            'has_previous_page' => $this->hasPreviousPage,
            'total' => $this->total,
            'stale_cursor' => $this->staleCursor,
            'per_page' => $this->perPage,
        ];
    }
}
