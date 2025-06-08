<?php

namespace App\Http\Integrations\Discogs\Filters;

class LabelFilter extends BaseFilter
{
    public function __construct(
        public ?string $q = null,
        public ?string $type = null,
        public ?string $title = null,
        public ?string $country = null,
        int $page = 1,
        int $per_page = 50
    ) {
        parent::__construct($page, $per_page);
    }

    protected function buildQuery(): array
    {
        $query = [];
        
        if ($this->q) {
            $query['q'] = $this->q;
        }
        
        if ($this->type) {
            $query['type'] = $this->type;
        }
        
        if ($this->title) {
            $query['title'] = $this->title;
        }
        
        if ($this->country) {
            $query['country'] = $this->country;
        }
        
        return $query;
    }
}