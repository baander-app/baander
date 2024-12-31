<?php

namespace App\Http\Integrations\MusicBrainz\Filters;

class LabelFilter extends BaseFilter
{
    public function __construct(
        public ?string $name = null,
        public ?string $country = null,
        int $limit = 25,
        int $offset = 0
    ) {
        parent::__construct($limit, $offset);
    }

    protected function buildQuery(): array
    {
        $query = [];
        if ($this->name) {
            $query[] = 'label:' . $this->name;
        }
        if ($this->country) {
            $query[] = 'country:' . $this->country;
        }
        return $query;
    }
}