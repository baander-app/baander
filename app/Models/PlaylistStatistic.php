<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class PlaylistStatistic extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'playlist_id',
        'views',
        'plays',
        'shares',
        'favorites',
    ];

    public function playlist()
    {
        return $this->belongsTo(Playlist::class);
    }
}
