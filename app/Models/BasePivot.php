<?php

namespace App\Models;

use App\Modules\Eloquent\BaseBuilder;
use GeneaLabs\LaravelPivotEvents\Traits\PivotEventTrait;
use Illuminate\Database\Eloquent\Relations\Pivot;

abstract class BasePivot extends Pivot
{
    public $incrementing = true;
    protected $dateFormat = 'Y-m-d H:i:sO';

    public function newEloquentBuilder($query)
    {
        return new BaseBuilder($query);
    }
}
