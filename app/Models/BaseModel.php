<?php

namespace App\Models;

use App\Support\BaseBuilder;
use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    protected $dateFormat = 'Y-m-d H:i:sO';

    public function formatForException()
    {
        return implode('|', [
            get_class($this), "id:$$this->id",
        ]);
    }

    public function newEloquentBuilder($query)
    {
        return new BaseBuilder($query);
    }
}
