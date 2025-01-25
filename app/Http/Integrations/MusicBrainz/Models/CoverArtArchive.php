<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class CoverArtArchive extends Data
{
    public function __construct(public mixed $cover_art_archive, public mixed $artwork, public mixed $count, public mixed $front, public mixed $back, public mixed $darkened)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            cover_art_archive: $data['cover-art-archive'] ?? null,
            artwork: $data['artwork'] ?? null,
            count: $data['count'] ?? null,
            front: $data['front'] ?? null,
            back: $data['back'] ?? null,
            darkened: $data['darkened'] ?? null
        );
    }
}