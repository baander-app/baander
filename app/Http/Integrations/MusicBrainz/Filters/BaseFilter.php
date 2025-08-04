<?php

namespace App\Http\Integrations\MusicBrainz\Filters;

use Spatie\LaravelData\Data;

abstract class BaseFilter extends Data
{
    public function __construct(
        public int $limit = 25,
        public int $offset = 0,
    ) {}

    abstract protected function buildQuery(): array;

    public function toQueryParameters(): array
    {
        return [
            'query' => implode(' AND ', $this->buildQuery()),
            'limit' => $this->limit,
            'offset' => $this->offset,
            'fmt' => 'json',
        ];
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function setLimit(int $limit): void
    {
        $this->limit = $limit;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function setOffset(int $offset): void
    {
        $this->offset = $offset;
    }
}