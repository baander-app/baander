<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class ArtistCredit extends Data
{
    public function __construct(public mixed $artist_credit, public mixed $id)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            artist_credit: $data['artist-credit'] ?? null,
            id: $data['id'] ?? null
        );
    }
}