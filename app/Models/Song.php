<?php

namespace App\Models;

use App\Filters\FilterBuilder;
use App\Models\Player\PlayerState;
use App\Packages\Http\Concerns\DirectStreamableFile;
use App\Packages\Nanoid\Concerns\HasNanoPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property string $librarySlug Available in SongController
 */
class Song extends BaseModel implements DirectStreamableFile
{
    use HasFactory, HasNanoPublicId;

    protected $fillable = [
        'title',
        'year',
        'comment',
        'disc',
        'length',
        'lyrics',
        'modified_time',
        'path',
        'track',
        'hash',
        'size',
        'mime_type',
    ];

    protected $with = ['album'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getMimeType(): string
    {
        return $this->mime_type;
    }

    public function album()
    {
        return $this->belongsTo(Album::class);
    }

    public function artists()
    {
        return $this->belongsToMany(Artist::class)
            ->using(ArtistSong::class);
    }

    public function albumArtist()
    {
        return $this->belongsTo(Artist::class, '');
    }

    public function genres()
    {
        return $this->morphToMany(Genre::class, 'genreables');
    }

    public function userMediaActivies()
    {
        return $this->morphToMany(UserMediaActivity::class, 'userMediaActivityable');
    }

    public function playerStates()
    {
        return $this->morphToMany(PlayerState::class, 'playable');
    }

    /**
     * @param $query
     * @param array $filters
     * @return \Tpetry\PostgresqlEnhanced\Query\Builder
     */
    public function scopeFilterBy($query, array $filters)
    {
        $namespace = 'App\Filters\SongFilters';
        $filter = new FilterBuilder($query, $filters, $namespace);

        return $filter->apply();
    }
}
