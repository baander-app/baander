<?php

namespace App\Http\Integrations\Discogs\Models;

use Spatie\LaravelData\Data;

abstract class Model extends Data
{
    /**
     * Create a new model instance from API data
     *
     * @param array $data API response data
     * @return static
     */
    abstract public static function fromApiData(array $data): self;
}