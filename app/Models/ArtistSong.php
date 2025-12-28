<?php

namespace App\Models;

class ArtistSong extends BasePivot
{
    protected $casts = [
        'role' => AlbumRole::class,
    ];

    public function artist()
    {
        return $this->belongsTo(Artist::class);
    }

    public function song()
    {
        return $this->belongsTo(Song::class);
    }
}
