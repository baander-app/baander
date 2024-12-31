<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Sluggable\{HasSlug, SlugOptions};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class Library extends BaseModel
{
    use HasFactory, HasSlug;

    protected $fillable = [
        'name',
        'slug',
        'path',
        'type',
        'order',
        'last_scan',
    ];

    /**
     * Get the options for generating the slug.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function updateLastScan(Carbon $date = null): void
    {
        $this->update([
            'last_scan' => $date ?? now(),
        ]);
    }

    public function getDisk()
    {
        return Storage::build([
            'driver' => 'local',
            'root'   => $this->path,
        ]);
    }

    public function albums()
    {
        return $this->hasMany(Album::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class)
            ->using(UserLibrary::class);
    }
}
