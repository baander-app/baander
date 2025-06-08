<?php

namespace App\Http\Integrations\Discogs\Filters;

use Spatie\LaravelData\Data;

abstract class BaseFilter extends Data
{
    public function __construct(
        public int $page = 1,
        public int $per_page = 50
    ) {}

    abstract protected function buildQuery(): array;

    public function toQueryParameters(): array
    {
        $params = [
            'page' => $this->page,
            'per_page' => $this->per_page,
        ];

        $queryParams = $this->buildQuery();
        if (!empty($queryParams)) {
            $params = array_merge($params, $queryParams);
        }

        return $params;
    }
}