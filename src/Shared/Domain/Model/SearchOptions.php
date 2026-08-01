<?php

declare(strict_types=1);

namespace App\Shared\Domain\Model;

final readonly class SearchOptions
{
    /**
     * @param string $query Search query string
     * @param int $limit Maximum number of results
     * @param int $offset Number of results to skip
     * @param list<string> $fields Searchable field names
     * @param float $minScore Minimum relevance score threshold
     * @param list<array{field: string, operator: string, value: mixed}> $filters Filter criteria
     * @param Cursor|null $cursor Cursor for keyset pagination
     * @param string|null $sortField Field name to sort by
     * @param string $sortOrder Sort direction ('asc' or 'desc')
     */
    private function __construct(
        private string $query,
        private int $limit,
        private int $offset,
        private array $fields,
        private float $minScore,
        private array $filters,
        private ?Cursor $cursor = null,
        private ?string $sortField = null,
        private string $sortOrder = 'asc',
    ) {
    }

    public static function create(string $query, int $limit = 50, int $offset = 0): self
    {
        return new self(
            query: $query,
            limit: $limit,
            offset: $offset,
            fields: [],
            minScore: 0.0,
            filters: [],
        );
    }

    public function withFields(array $fields): self
    {
        return new self(
            query: $this->query,
            limit: $this->limit,
            offset: $this->offset,
            fields: $fields,
            minScore: $this->minScore,
            filters: $this->filters,
            cursor: $this->cursor,
            sortField: $this->sortField,
            sortOrder: $this->sortOrder,
        );
    }

    public function withMinScore(float $minScore): self
    {
        return new self(
            query: $this->query,
            limit: $this->limit,
            offset: $this->offset,
            fields: $this->fields,
            minScore: $minScore,
            filters: $this->filters,
            cursor: $this->cursor,
            sortField: $this->sortField,
            sortOrder: $this->sortOrder,
        );
    }

    public function withFilters(array $filters): self
    {
        return new self(
            query: $this->query,
            limit: $this->limit,
            offset: $this->offset,
            fields: $this->fields,
            minScore: $this->minScore,
            filters: $filters,
            cursor: $this->cursor,
            sortField: $this->sortField,
            sortOrder: $this->sortOrder,
        );
    }

    public function withCursor(?Cursor $cursor): self
    {
        return new self(
            query: $this->query,
            limit: $this->limit,
            offset: $this->offset,
            fields: $this->fields,
            minScore: $this->minScore,
            filters: $this->filters,
            cursor: $cursor,
            sortField: $this->sortField,
            sortOrder: $this->sortOrder,
        );
    }

    public function withSort(string $field, string $order = 'asc'): self
    {
        return new self(
            query: $this->query,
            limit: $this->limit,
            offset: $this->offset,
            fields: $this->fields,
            minScore: $this->minScore,
            filters: $this->filters,
            cursor: $this->cursor,
            sortField: $field,
            sortOrder: $order,
        );
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getFields(): array
    {
        return $this->fields;
    }

    public function getMinScore(): float
    {
        return $this->minScore;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getCursor(): ?Cursor
    {
        return $this->cursor;
    }

    public function getSortField(): ?string
    {
        return $this->sortField;
    }

    public function getSortOrder(): string
    {
        return $this->sortOrder;
    }

    public function hasQuery(): bool
    {
        return trim($this->query) !== '';
    }

    public function hasSort(): bool
    {
        return $this->sortField !== null;
    }
}
