<?php

namespace App\Models;

class MovieVideo extends BasePivot
{
    public $timestamps = false;

    protected $fillable = [
        'order',
    ];

    public function movie()
    {
        return $this->belongsTo(Movie::class);
    }

    public function video()
    {
        return $this->belongsTo(Video::class);
    }
}
