<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class IsniList extends Data
{
    public function __construct(public mixed $isni)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            isni: $data['isni'] ?? null
        );
    }
}