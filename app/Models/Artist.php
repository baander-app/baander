<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Sluggable\{HasSlug, SlugOptions};

class Artist extends BaseModel
{
    use HasFactory, HasSlug;

    public static array $filterFields = [
        'name',
        'slug',
    ];

    public static array $filterRelations = [
        'songs',
    ];

    protected $fillable = [
        'name',
        'slug',
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

    public function albums()
    {
        return $this->belongsToMany(Album::class)
            ->using(AlbumArtist::class);
    }

    public function songs()
    {
        return $this->belongsToMany(Song::class)
            ->using(ArtistSong::class);
    }

    public function portrait()
    {
        return $this->morphOne(Image::class, 'imageable');
    }
}
