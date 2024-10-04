<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class GenreSong extends Pivot
{
    public $timestamps = false;

    public function genre()
    {
        return $this->belongsTo(Genre::class);
    }

    public function song()
    {
        return $this->belongsTo(Song::class);
    }
}
