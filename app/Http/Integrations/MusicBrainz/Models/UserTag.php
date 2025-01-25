<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class UserTag extends Data
{
    public function __construct(public mixed $user_tag, public mixed $name)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            user_tag: $data['user-tag'] ?? null,
            name: $data['name'] ?? null
        );
    }
}