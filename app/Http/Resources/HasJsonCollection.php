<?php

namespace App\Http\Resources;

use App\Modules\Http\Resources\Json\JsonAnonymousResourceCollection;

trait HasJsonCollection
{
    public static function collection($resource)
    {
        return tap(new JsonAnonymousResourceCollection($resource, static::class), function ($collection) {
            if (property_exists(static::class, 'preserveKeys')) {
                $collection->preserveKeys = new static([])->preserveKeys === true;
            }
        });
    }
}