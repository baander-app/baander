<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class FreedbDisc extends Data
{
    public function __construct(public mixed $freedb_disc, public mixed $title, public mixed $count, public mixed $offset, public mixed $track, public mixed $length, public mixed $artist, public mixed $id, public mixed $category, public mixed $year)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            freedb_disc: $data['freedb-disc'] ?? null,
            title: $data['title'] ?? null,
            count: $data['count'] ?? null,
            offset: $data['offset'] ?? null,
            track: $data['track'] ?? null,
            length: $data['length'] ?? null,
            artist: $data['artist'] ?? null,
            id: $data['id'] ?? null,
            category: $data['category'] ?? null,
            year: $data['year'] ?? null
        );
    }
}