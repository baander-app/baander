<?php

namespace App\Extensions;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;

class BaseBuilder extends Builder
{
    protected function paginator($items, $total, $perPage, $currentPage, $options)
    {
        return Container::getInstance()->makeWith(JsonPaginator::class, compact(
            'items', 'total', 'perPage', 'currentPage', 'options'
        ));
    }
}