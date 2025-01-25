<?php

namespace App\Http\Integrations\MusicBrainz\Models;

use Spatie\LaravelData\Data;

class Cancelled extends Data
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