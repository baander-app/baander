<?php

namespace App\Http\Integrations\Discogs\Filters;

use Spatie\LaravelData\Data;

abstract class BaseFilter extends Data
{
    public function __construct(
        public int $page = 1,
        public int $per_page = 50
    ) {}

    public function setPage(int $page): static
    {
        $this->page = $page;
        return $this;
    }

    public function setPerPage(int $perPage): static
    {
        $this->per_page = $perPage;
        return $this;
    }

    abstract protected function buildQuery(): array;

    public function toQueryParameters(): array
    {
        // Discogs API uses offset and limit instead of page and per_page
        $offset = ($this->page - 1) * $this->per_page;

        $params = [
            'page' => $this->page,
            'per_page' => $this->per_page,
            'offset' => $offset,
            'limit' => $this->per_page,
        ];

        $queryParams = $this->buildQuery();
        if (!empty($queryParams)) {
            $params = array_merge($params, $queryParams);
        }

        return $params;
    }
}