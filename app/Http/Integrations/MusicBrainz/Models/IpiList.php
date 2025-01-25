<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class IpiList extends Data
{
    public function __construct(public mixed $ipi)
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            ipi: $data['ipi'] ?? null
        );
    }
}