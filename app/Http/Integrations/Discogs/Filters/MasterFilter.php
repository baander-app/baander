<?php

namespace App\Http\Integrations\Discogs\Filters;

class MasterFilter extends BaseFilter
{
    public function __construct(
        public ?string $q = null,
        public ?string $type = null,
        public ?string $title = null,
        public ?string $artist = null,
        public ?string $country = null,
        public ?string $year = null,
        public ?string $genre = null,
        public ?string $style = null,
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
        
        if ($this->artist) {
            $query['artist'] = $this->artist;
        }
        
        if ($this->country) {
            $query['country'] = $this->country;
        }
        
        if ($this->year) {
            $query['year'] = $this->year;
        }
        
        if ($this->genre) {
            $query['genre'] = $this->genre;
        }
        
        if ($this->style) {
            $query['style'] = $this->style;
        }
        
        return $query;
    }
}