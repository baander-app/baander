<?php

namespace App\Models;

use App\Modules\Nanoid\Concerns\HasNanoPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Movie extends BaseModel
{
    use HasFactory, HasNanoPublicId;

    protected $fillable = [
        'title',
        'year',
        'summary',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function library()
    {
        return $this->belongsTo(Library::class);
    }

    public function videos()
    {
        return $this->belongsToMany(Video::class)
            ->using(MovieVideo::class)
            ->withPivot('order');
    }
}
