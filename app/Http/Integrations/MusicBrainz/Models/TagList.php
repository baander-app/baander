<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class TagList extends Data
{
    public function __construct(public mixed $count, public mixed $offset, public mixed $tag, public mixed $name)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            count: $data['count'] ?? null,
            offset: $data['offset'] ?? null,
            tag: $data['tag'] ?? null,
            name: $data['name'] ?? null
        );
    }
}