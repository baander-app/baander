<?php

namespace App\Http\Integrations\MusicBrainz\Filters;

class RecordingFilter extends BaseFilter
{
    public function __construct(
        public ?string $title = null,
        public ?string $artistName = null,
        public ?string $release = null,
        int            $limit = 25,
        int            $offset = 0,
    )
    {
        parent::__construct($limit, $offset);
    }

    public function setTitle(?string $title): RecordingFilter
    {
        $this->title = $title;
        return $this;
    }

    public function setArtistName(?string $artistName): RecordingFilter
    {
        $this->artistName = $artistName;
        return $this;
    }

    public function getRelease(): ?string
    {
        return $this->release;
    }

    public function setRelease(?string $release): void
    {
        $this->release = $release;
    }

    protected function buildQuery(): array
    {
        $query = [];

        if ($this->title) {
            $query[] = 'recording:' . $this->title;
        }
        if ($this->artistName) {
            $query[] = 'artist:' . $this->artistName;
        }
        if ($this->release) {
            $query[] = 'release:' . $this->release;
        }

        return $query;
    }
}