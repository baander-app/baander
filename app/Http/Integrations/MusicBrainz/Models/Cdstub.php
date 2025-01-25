<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class Cdstub extends Data
{
    public function __construct(public mixed $title, public mixed $id, public mixed $count, public mixed $offset, public mixed $track, public mixed $length, public mixed $artist, public mixed $barcode, public mixed $disambiguation)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            title: $data['title'] ?? null,
            id: $data['id'] ?? null,
            count: $data['count'] ?? null,
            offset: $data['offset'] ?? null,
            track: $data['track'] ?? null,
            length: $data['length'] ?? null,
            artist: $data['artist'] ?? null,
            barcode: $data['barcode'] ?? null,
            disambiguation: $data['disambiguation'] ?? null
        );
    }
}