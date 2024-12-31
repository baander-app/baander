<?php

namespace App\Packages\Nanoid\Concerns;

use App\Packages\Nanoid\NanoId;

trait HasNanoPublicId
{
    public static function bootHasNanoPublicId()
    {
        static::creating(function ($model) {
            $model->public_id = NanoId::generateId();
        });
    }
}