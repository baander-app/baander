<?php

namespace App\Models;

use App\Models\Concerns\HasLibraryAccess;
use App\Modules\Eloquent\BaseBuilder;
use App\Modules\Nanoid\Concerns\HasNanoPublicId;
use App\Modules\Translation\LocaleString;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Overtrue\LaravelVersionable\Versionable;
use Spatie\Sluggable\{HasSlug, SlugOptions};
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Album extends BaseModel implements HasMedia
{
    use HasNanoPublicId, HasFactory, HasLibraryAccess, Versionable, InteractsWithMedia;

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
        'year',
        'mbid',
        'discogs_id',
    ];

    protected $versionable = [
        'title',
        'year',
        'mbid',
        'discogs_id',
    ];

    protected $perPage = 60;


    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaCollection('cover')
            ->withResponsiveImages();
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function getTitleAttribute()
    {
        $title = $this->attributes['title'];

        if (LocaleString::isLocaleString($title)) {
            return __(LocaleString::removeDelimiters($title));
        }

        return $title;
    }

    /**
     * Check if this album is marked as unknown
     */
    public function isUnknown(): bool
    {
        return LocaleString::isLocaleString($this->attributes['title'] ?? '');
    }

    /**
     * Check if metadata lookup should be skipped for this album
     */
    public function shouldSkipMetadataLookup(): bool
    {
        return $this->isUnknown();
    }

    /**
     * Create an unknown album with localized title
     */
    public static function createUnknown(array $attributes = []): static
    {
        return static::create(array_merge([
            'title' => LocaleString::delimitString('media.unknown_album'),
        ], $attributes));
    }

    public function getRouteKey()
    {
        return 'public_id';
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

    protected function scopeWhereGenreNames(BaseBuilder $q, array|string $names)
    {
        if (is_string($names)) {
            $names = explode(',', $names);
        }

        return $q->whereHas('songs', function ($q) use ($names) {
            $q->whereHas('genres', function ($q) use ($names) {
                $q->whereIn('name', $names);
            });
        });
    }
}
