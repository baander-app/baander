<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class Rating extends Data
{
    public function __construct(public mixed $votes_count)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            votes_count: $data['votes-count'] ?? null
        );
    }
}