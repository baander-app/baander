<?php

namespace App\Http\Integrations\Discogs\Filters;

class ReleaseFilter extends BaseFilter
{
    public function __construct(
        public ?string $q = null,
        public ?string $type = null,
        public ?string $title = null,
        public ?string $artist = null,
        public ?string $label = null,
        public ?string $country = null,
        public ?string $year = null,
        public ?string $format = null,
        public ?string $genre = null,
        public ?string $style = null,
        int $page = 1,
        int $per_page = 50
    ) {
        parent::__construct($page, $per_page);
    }

    public function setQ(?string $q): ReleaseFilter
    {
        $this->q = $q;
        return $this;
    }

    public function setType(?string $type): ReleaseFilter
    {
        $this->type = $type;
        return $this;
    }

    public function setTitle(?string $title): ReleaseFilter
    {
        $this->title = $title;
        return $this;
    }

    public function setArtist(?string $artist): ReleaseFilter
    {
        $this->artist = $artist;
        return $this;
    }

    public function setLabel(?string $label): ReleaseFilter
    {
        $this->label = $label;
        return $this;
    }

    public function setCountry(?string $country): ReleaseFilter
    {
        $this->country = $country;
        return $this;
    }

    public function setYear(?string $year): ReleaseFilter
    {
        $this->year = $year;
        return $this;
    }

    public function setFormat(?string $format): ReleaseFilter
    {
        $this->format = $format;
        return $this;
    }

    public function setGenre(?string $genre): ReleaseFilter
    {
        $this->genre = $genre;
        return $this;
    }

    public function setStyle(?string $style): ReleaseFilter
    {
        $this->style = $style;
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
        
        if ($this->artist) {
            $query['artist'] = $this->artist;
        }
        
        if ($this->label) {
            $query['label'] = $this->label;
        }
        
        if ($this->country) {
            $query['country'] = $this->country;
        }
        
        if ($this->year) {
            $query['year'] = $this->year;
        }
        
        if ($this->format) {
            $query['format'] = $this->format;
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