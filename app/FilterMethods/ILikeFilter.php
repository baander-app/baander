<?php

namespace App\FilterMethods;

use Illuminate\Database\Eloquent\Builder;
use IndexZer0\EloquentFiltering\Filter\Contracts\FilterMethod;
use IndexZer0\EloquentFiltering\Filter\Contracts\FilterMethod\Targetable;
use IndexZer0\EloquentFiltering\Filter\Traits\FilterMethod\FilterContext\FieldFilter;

class ILikeFilter implements FilterMethod, Targetable
{
    use FieldFilter;

    public function __construct(
        protected mixed $value,
    )
    {
    }

    /*
     * The unique identifier of the filter.
     */
    public static function type(): string
    {
        return '$iLike';
    }

    /*
     * The format that the filter data must adhere to.
     * Defined as laravel validator rules.
     * On fail: throws MalformedFilterFormatException.
     */
    public static function format(): array
    {
        return [
            'value' => ['required'],
        ];
    }

    /*
     * Apply the filter logic.
     */
    public function apply(Builder $query): Builder
    {
        return $query->where(
            $this->eloquentContext->qualifyColumn($this->target),
            'ilike',
            $this->value,
        );
    }
}
