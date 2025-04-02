<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class PlaylistCollaborator extends Pivot
{
    protected $fillable = [
        'role',
    ];

    public function playlist()
    {
        return $this->belongsTo(Playlist::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
