<?php

namespace App\Models;

use App\Extensions\BaseBuilder;
use App\Filters\FilterBuilder;
use App\Models\Concerns\HasLibraryAccess;
use App\Models\Player\PlayerState;
use App\Packages\Http\Concerns\DirectStreamableFile;
use App\Packages\Nanoid\Concerns\HasNanoPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property string $librarySlug Available in SongController
 */
class Song extends BaseModel implements DirectStreamableFile
{
    use HasFactory, HasLibraryAccess, HasNanoPublicId;

    public static array $filterRelations = [
        'album',
        'album.cover',
        'artists',
        'albumArtist',
        'genres',
    ];

    protected $perPage = 30;

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

    protected function scopeWhereGenreNames(BaseBuilder $q, array $names)
    {
        return $q->whereHas('genres', function ($q) use ($names) {
            $q->whereIn('name', $names);
        });
    }

    protected function scopeWhereGenreSlugs(BaseBuilder $q, array $slugs)
    {
        return $q->whereHas('genres', function ($q) use ($slugs) {
            $q->whereIn('name', $slugs);
        });
    }
}
