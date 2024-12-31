<?php

namespace App\Models;

use App\Extensions\Eloquent\BaseBuilder;
use App\Models\Concerns\HasLibraryAccess;
use App\Models\Player\PlayerState;
use App\Modules\Http\Concerns\DirectStreamableFile;
use App\Modules\Nanoid\Concerns\HasNanoPublicId;
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
        'genres',
    ];

    protected $perPage = 30;

    protected $with = ['album'];

    protected $fillable = [
        'public_id',
        'album_id',
        'title',
        'path',
        'size',
        'mime_type',
        'length',
        'lyrics',
        'track',
        'disc',
        'modified_time',
        'year',
        'comment',
        'hash',
        'librarySlug',
    ];

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

    public function userMediaActivies()
    {
        return $this->morphToMany(UserMediaActivity::class, 'userMediaActivityable');
    }

    public function playerStates()
    {
        return $this->morphToMany(PlayerState::class, 'playable');
    }

    public function genres()
    {
        return $this->belongsToMany(Genre::class)
            ->using(GenreSong::class);
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
