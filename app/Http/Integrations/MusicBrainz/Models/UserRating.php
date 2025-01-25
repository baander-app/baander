<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class UserRating extends Data
{
    public function __construct(public mixed $user_rating)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            user_rating: $data['user-rating'] ?? null
        );
    }
}