<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class CdstubList extends Data
{
    public function __construct(public mixed $count, public mixed $offset, public mixed $cdstub, public mixed $title, public mixed $id, public mixed $track, public mixed $length, public mixed $artist, public mixed $barcode, public mixed $disambiguation)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            count: $data['count'] ?? null,
            offset: $data['offset'] ?? null,
            cdstub: $data['cdstub'] ?? null,
            title: $data['title'] ?? null,
            id: $data['id'] ?? null,
            track: $data['track'] ?? null,
            length: $data['length'] ?? null,
            artist: $data['artist'] ?? null,
            barcode: $data['barcode'] ?? null,
            disambiguation: $data['disambiguation'] ?? null
        );
    }
}