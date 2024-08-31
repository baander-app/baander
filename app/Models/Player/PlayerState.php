<?php

namespace App\Models\Player;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PlayerState extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'is_active',
        'is_playing',
        'name',
        'type',
        'progress_ms',
        'volume_percent',
        'playable_id',
        'playable_type',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function playable()
    {
        return $this->morphTo();
    }
}
