<?php

namespace App\Models;

use App\Models\Concerns\HasLibraryAccess;
use App\Modules\Eloquent\BaseBuilder;
use App\Modules\Http\Concerns\DirectStreamableFile;
use App\Modules\Nanoid\Concerns\HasNanoPublicId;
use App\Modules\Recommendation\Concerns\Recommendable;
use App\Modules\Recommendation\HasRecommendation;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property string $librarySlug Available in SongController
 */
class Song extends BaseModel implements DirectStreamableFile, Recommendable
{
    use HasFactory, HasLibraryAccess, HasNanoPublicId, HasRecommendation;

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
        'mbid',
        'discogs_id',
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

    public static function getRecommendationConfig(): array
    {
        return [
            'similar_genre'         => [
                'algorithm'       => 'music_genre',
                'data_table'      => 'genre_song',
                'data_field'      => 'song_id',
                'data_field_type' => self::class,
                'group_field'     => 'genre_id',
                'count'           => 10,
            ],
            'user_listening_habits' => [
                'algorithm'           => 'user_listening_habits',
                'user_activity_table' => 'user_media_activities',
                'media_field'         => 'user_media_activityable_id',
                'media_type_field'    => 'user_media_activityable_type',
                'media_type'          => self::class,
                'play_count_field'    => 'play_count',
                'love_field'          => 'love',
                'count'               => 10,
            ],
        ];
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
        return $this->morphToMany(UserMediaActivity::class, 'user_media_activityable');
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
