<?php

namespace App\Filters\SongFilters;

use App\Filters\Contracts\Filterable;
use App\Filters\QueryFilter;

class AlbumArtist extends QueryFilter implements Filterable
{
    public function handle($value): void
    {
        $this->query->whereHas('albumArtist', function ($query) use ($value) {
            return $query->where('name', $value);
        });
    }
}