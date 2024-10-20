<?php

namespace App\Models;

use App\Extensions\BaseBuilder;
use GeneaLabs\LaravelPivotEvents\Traits\PivotEventTrait;
use Illuminate\Database\Eloquent\Relations\Pivot;

abstract class BasePivot extends Pivot
{
    use PivotEventTrait;

    public $incrementing = true;
    protected $dateFormat = 'Y-m-d H:i:sO';

    public function newEloquentBuilder($query)
    {
        return new BaseBuilder($query);
    }
}
