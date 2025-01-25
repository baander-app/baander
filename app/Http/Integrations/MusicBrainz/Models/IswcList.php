<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class IswcList extends Data
{
    public function __construct(public mixed $count, public mixed $offset, public mixed $iswc)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            count: $data['count'] ?? null,
            offset: $data['offset'] ?? null,
            iswc: $data['iswc'] ?? null
        );
    }
}