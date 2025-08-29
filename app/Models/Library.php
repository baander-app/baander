<?php

namespace App\Models;

use App\Models\Concerns\HasLibraryStats;
use App\Models\Concerns\HasMeta;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Sluggable\{HasSlug, SlugOptions};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class Library extends BaseModel
{
    use HasFactory, HasSlug, HasLibraryStats, HasMeta;

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

    public function updateLastScan(?Carbon $date = null): void
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

    /**
     * Get a summary dashboard for this library
     */
    public function getDashboardData(): array
    {
        $stats = $this->getFormattedStats();

        return [
            'library' => [
                'name' => $this->name,
                'slug' => $this->slug,
                'last_scan' => $this->last_scan?->diffForHumans(),
            ],
            'statistics' => $stats,
            'recent_additions' => $this->albums()
                ->with(['songs', 'artists'])
                ->latest()
                ->limit(5)
                ->get(),
        ];
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
