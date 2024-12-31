<?php

namespace App\Http\Integrations\MusicBrainz\Filters;

class ArtistFilter extends BaseFilter
{
    public function __construct(
        public ?string $name = null,
        public ?string $country = null,
        public ?string $type = null,
        int $limit = 25,
        int $offset = 0
    ) {
        parent::__construct($limit, $offset);
    }

    protected function buildQuery(): array
    {
        $query = [];
        if ($this->name) {
            $query[] = 'artist:' . $this->name;
        }
        if ($this->country) {
            $query[] = 'country:' . $this->country;
        }
        if ($this->type) {
            $query[] = 'type:' . $this->type;
        }
        return $query;
    }
}