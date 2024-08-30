<?php

namespace App\Filters\SongFilters;

use App\Filters\Contracts\Filterable;
use App\Filters\QueryFilter;

class Title extends QueryFilter implements Filterable
{
    public function handle($value): void
    {
        $this->query->where('title', $value);
    }
}