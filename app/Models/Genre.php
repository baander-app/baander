<?php

namespace App\Models;

use App\Models\Concerns\HasRecursiveRelationships;
use App\Modules\Eloquent\RecursiveBaseBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Spatie\Sluggable\{HasSlug, SlugOptions};

class Genre extends BaseModel
{
    use HasFactory, HasSlug, HasRecursiveRelationships;

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
        'parent_id',
        'mbid',
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

    public function songs()
    {
        return $this->belongsToMany(Song::class)
            ->using(GenreSong::class);
    }

    /**
     * Create a new Eloquent query builder for the model.
     * Override to use RecursiveBaseBuilder for hierarchical queries.
     *
     * @param QueryBuilder $query
     * @return RecursiveBaseBuilder
     */
    public function newEloquentBuilder($query): RecursiveBaseBuilder
    {
        return new RecursiveBaseBuilder($query);
    }

    /**
     * Get the MusicBrainz URL for the genre.
     *
     * @return string|null
     */
    public function getMusicBrainzUrlAttribute(): ?string
    {
        return $this->mbid ? "https://musicbrainz.org/genre/{$this->mbid}" : null;
    }
}