<?php

namespace App\Support;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class BaseBuilder extends Builder
{
    protected function paginator($items, $total, $perPage, $currentPage, $options)
    {
        return Container::getInstance()->makeWith(JsonPaginator::class, compact(
            'items', 'total', 'perPage', 'currentPage', 'options'
        ));
    }
}