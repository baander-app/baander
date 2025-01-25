<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class NonmbTrackList extends Data
{
    public function __construct(public mixed $count, public mixed $offset, public mixed $track, public mixed $title, public mixed $length, public mixed $artist)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            count: $data['count'] ?? null,
            offset: $data['offset'] ?? null,
            track: $data['track'] ?? null,
            title: $data['title'] ?? null,
            length: $data['length'] ?? null,
            artist: $data['artist'] ?? null
        );
    }
}