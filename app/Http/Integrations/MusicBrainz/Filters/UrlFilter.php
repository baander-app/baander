<?php

namespace App\Http\Integrations\MusicBrainz\Filters;

class UrlFilter extends BaseFilter
{
    public function __construct(
        public ?string $resource = null,
        int $limit = 25,
        int $offset = 0
    ) {
        parent::__construct($limit, $offset);
    }

    protected function buildQuery(): array
    {
        $query = [];
        if ($this->resource) {
            $query[] = 'resource:' . $this->resource;
        }
        return $query;
    }
}