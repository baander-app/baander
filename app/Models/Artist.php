<?php

namespace App\Models;

use App\Format\LocaleString;
use App\Modules\Eloquent\Relations\BelongsToManyThrough;
use App\Modules\Nanoid\Concerns\HasNanoPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Artist extends BaseModel
{
    use HasFactory, HasNanoPublicId;

    public static array $filterFields = [
        'name',
        'slug',
    ];

    public static array $filterRelations = [
        'songs',
    ];

    protected $fillable = [
        'public_id',
        'name',
        'country',
        'gender',
        'type',
        'life_span_begin',
        'life_span_end',
        'disambiguation',
        'sort_name',
        'biography',
        'mbid',
        'discogs_id',
        'spotify_id',
        'locked_fields',
    ];

    protected $casts = [
        'life_span_begin' => 'date',
        'life_span_end'   => 'date',
        'locked_fields'   => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
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
     * Check if this artist has ended (died/disbanded)
     */
    public function hasEnded(): bool
    {
        return !is_null($this->life_span_end);
    }

    /**
     * Check if this artist is still active
     */
    public function isActive(): bool
    {
        return is_null($this->life_span_end);
    }

    /**
     * Scope to get artists that have ended
     */
    public function scopeEnded(Builder $query): Builder
    {
        return $query->whereNotNull('life_span_end');
    }

    /**
     * Scope to get active artists
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('life_span_end');
    }

    /**
     * Scope to get artists by type
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Create an unknown artist with localized name
     */
    public static function createUnknown(array $attributes = []): static
    {
        return static::create(array_merge([
            'name' => LocaleString::delimit('media.unknown_artist'),
        ], $attributes));
    }

    public function albums()
    {
        $instance = $this->newRelatedInstance(Album::class);

        $query = $instance->newQuery();

        return new BelongsToManyThrough(
            $query,
            $this,
            'songs',
            'artist_song',
            'artist_id',
            'song_id',
            'id',
            $instance->getKeyName(),
            'albums',
        )->setThroughKeys('album_id', 'id')
         ->groupBy('albums.id');
    }

    public function songs()
    {
        $instance = $this->newRelatedInstance(Song::class);

        $query = $instance->newQuery();

        return new BelongsToManyThrough(
            $query,
            $this,
            'songs',
            'artist_song',
            'artist_id',
            'song_id',
            $instance->getKeyName(),
            'songs',
        )->withPivot('role');
    }

    public function portrait()
    {
        return $this->morphOne(Image::class, 'imageable');
    }
}