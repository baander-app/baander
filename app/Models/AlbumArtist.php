<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class AlbumArtist extends BasePivot
{
    use HasFactory;

    public $timestamps = false;

    public function album()
    {
        return $this->belongsTo(Album::class);
    }

    public function artist()
    {
        return $this->belongsTo(Artist::class);
    }
}
