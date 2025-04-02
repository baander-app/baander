<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class PlaylistSong extends Pivot
{
    protected $table = 'playlist_song';

    protected $fillable = [
        'playlist_id',
        'song_id',
        'position',
    ];

    public function playlist()
    {
        return $this->belongsTo(Playlist::class);
    }

    public function song()
    {
        return $this->belongsTo(Song::class);
    }
}
