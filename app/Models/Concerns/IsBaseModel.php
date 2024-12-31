<?php

namespace App\Models\Concerns;

use App\Extensions\BaseBuilder;

/**
 * @method BaseBuilder query()
 */
trait IsBaseModel
{
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