<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class IswcElement extends Data
{
    public function __construct(public mixed $iswc)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            iswc: $data['iswc'] ?? null
        );
    }
}