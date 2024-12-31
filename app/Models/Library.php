<?php

namespace App\Models;

use App\Modules\JsonSchema\Eloquent\HasJsonSchema;
use App\Modules\JsonSchema\Eloquent\JsonSchemaRepresentable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Sluggable\{HasSlug, SlugOptions};
use Illuminate\Support\Facades\Storage;

class Library extends BaseModel implements JsonSchemaRepresentable
{
    use HasFactory, HasJsonSchema, HasSlug;

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

    public function getJsonSchemaFieldOptions(): array
    {
        return [
            'name'       => [
                'required' => true,
            ],
            'slug'       => [
                'required' => true,
                'readOnly' => true,
            ],
            'path'       => [
                'required' => true,
            ],
            'type'       => [
                'required' => true,
                'enum'     => LibraryType::values(),
            ],
            'last_scan'  => [
                'readOnly' => true,
            ],
            'created_at' => [
                'readOnly' => true,
            ],
            'updated_at' => [
                'readOnly' => true,
            ],
        ];
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
