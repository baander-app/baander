<?php

namespace App\Models;

class ArtistSong extends BasePivot
{
    public function artist()
    {
        return $this->belongsTo(Artist::class);
    }

    public function song()
    {
        return $this->belongsTo(Song::class);
    }
}
