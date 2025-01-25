<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class Iso31662CodeList extends Data
{
    public function __construct(public mixed $iso_3166_2_code)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            iso_3166_2_code: $data['iso-3166-2-code'] ?? null
        );
    }
}