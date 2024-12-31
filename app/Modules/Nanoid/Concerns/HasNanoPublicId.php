<?php

namespace App\Modules\Nanoid\Concerns;

use App\Modules\Nanoid\NanoId;

trait HasNanoPublicId
{
    public static function bootHasNanoPublicId()
    {
        static::creating(function ($model) {
            $model->public_id = NanoId::generateId();
        });
    }
}