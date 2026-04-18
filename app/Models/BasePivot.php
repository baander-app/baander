<?php

namespace App\Models;

use App\Modules\Eloquent\BaseBuilder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use App\Primitives\Text;

abstract class BasePivot extends Pivot
{
    public $incrementing = true;
    protected $dateFormat = 'Y-m-d H:i:sO';

    public function newEloquentBuilder($query)
    {
        return new BaseBuilder($query);
    }

    public function update(array $attributes = [], array $options = [])
    {
        $snakeCasedAttributes = [];
        foreach ($attributes as $key => $value) {
            $snakeCasedAttributes[Text::snake($key)->value()] = $value;
        }

        return parent::update($snakeCasedAttributes, $options);
    }

}
