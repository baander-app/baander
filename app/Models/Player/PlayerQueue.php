<?php

namespace App\Models\Player;

use App\Models\BaseModel;
use App\Models\Song;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PlayerQueue extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'order',
    ];

    public function song()
    {
        return $this->belongsTo(Song::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
