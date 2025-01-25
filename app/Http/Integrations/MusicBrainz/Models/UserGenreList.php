<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class UserGenreList extends Data
{
    public function __construct(public mixed $count, public mixed $offset, public mixed $user_genre, public mixed $name, public mixed $id, public mixed $disambiguation)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            count: $data['count'] ?? null,
            offset: $data['offset'] ?? null,
            user_genre: $data['user-genre'] ?? null,
            name: $data['name'] ?? null,
            id: $data['id'] ?? null,
            disambiguation: $data['disambiguation'] ?? null
        );
    }
}