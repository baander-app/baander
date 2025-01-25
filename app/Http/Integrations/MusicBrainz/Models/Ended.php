<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class Ended extends Data
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