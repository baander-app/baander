<?php

namespace App\Models;

use App\Models\Concerns\HasLibraryAccess;
use App\Modules\Eloquent\BaseBuilder;
use App\Modules\Translation\LocaleString;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Overtrue\LaravelVersionable\Versionable;
use Spatie\Sluggable\{HasSlug, SlugOptions};

class Album extends BaseModel
{
    use HasFactory, HasLibraryAccess, HasSlug, Versionable;

    public static array $filterFields = [
        'title',
        'slug',
        'year',
        'directory',
    ];

    public static array $filterRelations = [
        'artists',
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
        'mbid',
        'discogs_id',
    ];

    protected $versionable = [
        'title',
        'year',
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

    public function getTitleAttribute()
    {
        $title = $this->attributes['title'];

        if (LocaleString::isLocaleString($title)) {
            return __(LocaleString::removeDelimiters($title));
        }

        return $title;
    }

    public function artists()
    {
        return $this->belongsToMany(Artist::class)
            ->using(AlbumArtist::class);
    }

    public function cover(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    public function library()
    {
        return $this->belongsTo(Library::class);
    }

    public function songs()
    {
        return $this->hasMany(Song::class)->orderByNullsLast('track');
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
