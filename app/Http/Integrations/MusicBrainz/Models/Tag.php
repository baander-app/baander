<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class Tag extends Data
{
    public function __construct(public mixed $name, public mixed $count)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            name: $data['name'] ?? null,
            count: $data['count'] ?? null
        );
    }
}