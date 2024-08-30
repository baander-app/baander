<?php

namespace App\Filters;

use Tpetry\PostgresqlEnhanced\Query\Builder;

abstract class QueryFilter
{
    protected Builder $query;

    public function __construct($query)
    {
        $this->query = $query;
    }
}