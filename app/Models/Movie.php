<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Movie extends BaseModel
{
    use HasFactory, HasSlug;

    protected $fillable = [
        'title',
        'year',
        'summary',
    ];

    /**
     * Get the options for generating the slug.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(['title', 'year'])
            ->saveSlugsTo('slug');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
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
