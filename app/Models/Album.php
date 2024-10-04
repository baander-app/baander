<?php

namespace App\Models;

use App\Extensions\BaseBuilder;
use App\Models\Concerns\HasLibraryAccess;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Sluggable\{HasSlug, SlugOptions};

class Album extends BaseModel
{
    use HasFactory, HasLibraryAccess, HasSlug;

    public static array $filterFields = [
      'title',
      'slug',
      'year',
      'directory',
    ];

    public static array $filterRelations = [
      'albumArtist',
      'cover',
      'library',
      'songs',
      'songs.genres',
    ];

    public static array $advancedFilters = [
      'genres',
    ];

    protected $fillable = [
        'title',
        'slug',
        'year',
        'directory',
    ];

    protected $perPage = 60;

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

    public function albumArtist()
    {
        return $this->belongsTo(Artist::class, 'artist_id', 'id', '');
    }

    public function cover()
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    public function library()
    {
        return $this->belongsTo(Library::class);
    }

    public function songs()
    {
        return $this->hasMany(Song::class);
    }

    protected function scopeWhereGenreNames(BaseBuilder $q, array $names)
    {
        return $q->whereHas('songs', function ($q) use ($names) {
            $q->whereHas('genres', function ($q) use ($names) {
                $q->whereIn('name', $names);
            });
        });
    }
}
