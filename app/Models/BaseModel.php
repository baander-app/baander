<?php

namespace App\Models;

use App\Extensions\BaseBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * @method BaseBuilder query()
 */
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
