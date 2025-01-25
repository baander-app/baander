<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class UserTagList extends Data
{
    public function __construct(public mixed $count, public mixed $offset, public mixed $user_tag, public mixed $name)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            count: $data['count'] ?? null,
            offset: $data['offset'] ?? null,
            user_tag: $data['user-tag'] ?? null,
            name: $data['name'] ?? null
        );
    }
}