<?php

namespace App\Models;

use App\Modules\MediaLibrary\SmartPlaylistService;
use App\Modules\Nanoid\Concerns\HasNanoPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Playlist extends BaseModel
{
    use HasFactory, HasNanoPublicId;

    public static $filterRelations = [
        'cover',
        'songs',
        'songs.artists',
        'songs.album',
        'songs.genres',
        'songs.cover'
    ];

    protected $fillable = [
        'public_id',
        'user_id',
        'name',
        'description',
        'is_public',
        'is_smart',
        'is_collaborative',
        'smart_rules',
    ];

    protected $casts = [
        'smart_rules' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function booted()
    {
        static::created(function (Playlist $playlist) {
            $playlist->statistics()->exists() || $playlist->statistics()->create();
        });
    }

    public function syncSmartPlaylist()
    {
        if (!$this->is_smart) {
            return;
        }

        $service = app(SmartPlaylistService::class);
        $songs = $service->getSongsForRules($this->smart_rules);

        $this->songs()->sync(
            $songs->mapWithKeys(function ($song, $index) {
                return [$song->id => ['position' => $index + 1]];
            }),
        );
    }

    public function statistics()
    {
        return $this->hasOne(PlaylistStatistic::class);
    }

    public function incrementViews()
    {
        $this->statistics()->increment('views');
    }

    public function incrementPlays()
    {
        $this->statistics()->increment('plays');
    }

    public function incrementShares()
    {
        $this->statistics()->increment('shares');
    }

    public function incrementFavorites()
    {
        $this->statistics()->increment('favorites');
    }

    public function collaborators()
    {
        return $this->belongsToMany(User::class, 'playlist_collaborator')
            ->using(PlaylistCollaborator::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function isCollaborator(User $user)
    {
        return $this->collaborators()->where('user_id', $user->id)->exists();
    }

    public function canEdit(User $user)
    {
        return $this->user_id === $user->id ||
            $this->collaborators()->where('user_id', $user->id)
                ->where('role', 'editor')
                ->exists();
    }

    public function canAddSongs(User $user)
    {
        return $this->user_id === $user->id ||
            $this->is_collaborative ||
            $this->collaborators()->where('user_id', $user->id)->exists();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cover(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    public function songs()
    {
        return $this->belongsToMany(Song::class, 'playlist_song')
            ->using(PlaylistSong::class)
            ->withPivot('position')
            ->orderBy('position');
    }
}
