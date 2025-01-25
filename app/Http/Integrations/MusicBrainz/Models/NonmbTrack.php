<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class NonmbTrack extends Data
{
    public function __construct(public mixed $track, public mixed $title, public mixed $length, public mixed $artist)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            track: $data['track'] ?? null,
            title: $data['title'] ?? null,
            length: $data['length'] ?? null,
            artist: $data['artist'] ?? null
        );
    }
}