<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class LanguageList extends Data
{
    public function __construct(public mixed $count, public mixed $offset, public mixed $language, public mixed $fluency)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            count: $data['count'] ?? null,
            offset: $data['offset'] ?? null,
            language: $data['language'] ?? null,
            fluency: $data['fluency'] ?? null
        );
    }
}