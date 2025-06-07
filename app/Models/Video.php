<?php

namespace App\Models;

use App\Observers\VideoObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;

#[ObservedBy(VideoObserver::class)]
class Video extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'path',
        'duration',
        'height',
        'width',
        'video_bitrate',
        'framerate',
        'probe',
    ];

    protected $casts = [
        'probe' => 'array',
    ];

    public function movies()
    {
        return $this->belongsToMany(Movie::class)
            ->using(MovieVideo::class);
    }

    public static function makeHash(\SplFileInfo $file): string
    {
        $parts = [
            'path' => $file->getRealPath(),
            'size' => $file->getSize(),
        ];

        return hash('sha256', implode(',', $parts));
    }
}
