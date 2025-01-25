<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class AttributeList extends Data
{
    public function __construct()
    {
    }

    public static function fromApiData(array $data): self
    {
        return new self(
            
        );
    }
}