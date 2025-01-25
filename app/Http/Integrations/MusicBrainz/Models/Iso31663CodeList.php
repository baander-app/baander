<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class Iso31663CodeList extends Data
{
    public function __construct(public mixed $iso_3166_3_code)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            iso_3166_3_code: $data['iso-3166-3-code'] ?? null
        );
    }
}