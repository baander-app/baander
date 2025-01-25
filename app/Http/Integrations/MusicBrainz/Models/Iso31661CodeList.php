<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class Iso31661CodeList extends Data
{
    public function __construct(public mixed $iso_3166_1_code)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            iso_3166_1_code: $data['iso-3166-1-code'] ?? null
        );
    }
}