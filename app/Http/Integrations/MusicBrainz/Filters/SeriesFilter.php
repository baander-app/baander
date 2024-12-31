<?php

namespace App\Http\Integrations\MusicBrainz\Filters;
class SeriesFilter extends BaseFilter
{
    public function __construct(
        public ?string $name = null,
        int $limit = 25,
        int $offset = 0
    ) {
        parent::__construct($limit, $offset);
    }

    protected function buildQuery(): array
    {
        $query = [];
        if ($this->name) {
            $query[] = 'series:' . $this->name;
        }
        return $query;
    }
}