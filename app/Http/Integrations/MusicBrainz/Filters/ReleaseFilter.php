<?php

namespace App\Http\Integrations\MusicBrainz\Filters;

class ReleaseFilter extends BaseFilter
{
    public function __construct(
        public ?string $title = null,
        public ?string $artistName = null,
        int $limit = 25,
        int $offset = 0
    ) {
        parent::__construct($limit, $offset);
    }

    public function setTitle(?string $title): ReleaseFilter
    {
        $this->title = $title;
        return $this;
    }

    public function setArtistName(?string $artistName): ReleaseFilter
    {
        $this->artistName = $artistName;
        return $this;
    }

    protected function buildQuery(): array
    {
        $query = [];
        if ($this->title) {
            $query[] = 'release:' . $this->title;
        }
        if ($this->artistName) {
            $query[] = 'artist:' . $this->artistName;
        }
        return $query;
    }
}