<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class Alias extends Data
{
    public function __construct(public mixed $locale, public mixed $sort_name, public mixed $type, public mixed $type_id, public mixed $primary, public mixed $begin_date, public mixed $end_date)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            locale: $data['locale'] ?? null,
            sort_name: $data['sort-name'] ?? null,
            type: $data['type'] ?? null,
            type_id: $data['type-id'] ?? null,
            primary: $data['primary'] ?? null,
            begin_date: $data['begin-date'] ?? null,
            end_date: $data['end-date'] ?? null
        );
    }
}