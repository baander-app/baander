<?php

declare(strict_types=1);

namespace App\Shared\Domain\Model;

final readonly class SearchResult
{
    /**
     * @param list<mixed> $items Search result items (domain entities)
     * @param int $total Total number of matching results
     * @param float $highestScore Highest relevance score in the result set
     */
    private function __construct(
        private array $items,
        private int $total,
        private float $highestScore,
    ) {
    }

    /**
     * @param list<mixed> $items
     */
    public static function create(array $items, int $total, float $highestScore = 0.0): self
    {
        return new self(
            items: $items,
            total: $total,
            highestScore: $highestScore,
        );
    }

    public static function empty(): self
    {
        return new self(
            items: [],
            total: 0,
            highestScore: 0.0,
        );
    }

    /**
     * @return list<mixed>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getHighestScore(): float
    {
        return $this->highestScore;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
