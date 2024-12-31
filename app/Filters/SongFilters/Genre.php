<?php

namespace App\Filters\SongFilters;

use App\Filters\Contracts\Filterable;
use App\Filters\QueryFilter;
use Tpetry\PostgresqlEnhanced\Query\Builder;

class Genre extends QueryFilter implements Filterable
{
    public function handle($value): void
    {
        $this->query->whereHas('genre', function ($query) use ($value) {
            return $query->where('name', $value);
        });
    }
}