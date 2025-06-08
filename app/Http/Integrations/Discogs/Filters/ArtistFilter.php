<?php

namespace App\Http\Integrations\Discogs\Filters;

class ArtistFilter extends BaseFilter
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

    public function setQ(?string $q): ArtistFilter
    {
        $this->q = $q;
        return $this;
    }

    public function setType(?string $type): ArtistFilter
    {
        $this->type = $type;
        return $this;
    }

    public function setTitle(?string $title): ArtistFilter
    {
        $this->title = $title;
        return $this;
    }

    public function setCountry(?string $country): ArtistFilter
    {
        $this->country = $country;
        return $this;
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