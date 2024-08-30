<?php

namespace App\Models;

use App\Models\BaseModel;
use App\Packages\Nanoid\Concerns\HasNanoPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserMediaActivity extends BaseModel
{
    use HasFactory, HasNanoPublicId;

    protected $table = 'user_media_activities';

    protected $fillable = [
        'public_id',
        'play_count',
        'love',
        'last_played_at',
        'last_platform',
        'last_player',
    ];

    protected $casts = [
        'love' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function userMediaActivityable()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
