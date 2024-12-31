<?php

namespace App\Models\Concerns;

use App\Extensions\BaseBuilder;

/**
 * @method BaseBuilder query()
 */
trait IsBaseModel
{
    protected $dateFormat = 'Y-m-d H:i:sO';

    public function formatForException(): string
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