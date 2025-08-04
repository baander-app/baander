<?php

namespace App\Models;

use App\Modules\Translation\LocaleString;
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

    public function getNameAttribute()
    {
        $name = $this->attributes['name'];

        if (LocaleString::isLocaleString($name)) {
            return __(LocaleString::removeDelimiters($name));
        }

        return $name;
    }

    /**
     * Check if this artist is marked as unknown
     */
    public function isUnknown(): bool
    {
        return LocaleString::isLocaleString($this->attributes['name'] ?? '');
    }

    /**
     * Check if metadata lookup should be skipped for this artist
     */
    public function shouldSkipMetadataLookup(): bool
    {
        return $this->isUnknown();
    }

    /**
     * Create an unknown artist with localized name
     */
    public static function createUnknown(array $attributes = []): static
    {
        return static::create(array_merge([
            'name' => LocaleString::delimitString('media.unknown_artist'),
        ], $attributes));
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
