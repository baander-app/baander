<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Pagination\JsonPaginator;
use App\Http\Requests\Playlist\{CreatePlaylistRequest,
    CreateSmartPlaylistRequest,
    UpdatePlaylistRequest,
    UpdateSmartPlaylistRulesRequest};
use App\Http\Resources\Playlist\PlaylistResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Models\{Playlist, PlaylistStatistic, Song, TokenAbility, User};
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\{Delete, Get, Middleware, Post, Prefix, Put};

#[Middleware(['force.json'])]
#[Prefix('/playlists')]
class PlaylistController extends Controller
{
    /**
     * Get a collection of playlists
     *
     * @param Request $request
     * @return AnonymousResourceCollection<JsonPaginator<PlaylistResource>>
     */
    #[Get('', 'api.playlist.index', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function index(Request $request)
    {
        $playlists = Playlist::whereUserId($request->user()->id)
            ->orWhere('is_public', true)
            ->paginate();

        return PlaylistResource::collection($playlists);
    }

    #[Post('', 'api.playlist.store', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function store(CreatePlaylistRequest $request)
    {
        $playlist = new Playlist([
            'name'        => $request->get('name'),
            'description' => $request->get('description'),
            'is_public'   => $request->boolean('is_public'),
        ]);

        $playlist->user()->associate($request->user());
        $playlist->saveOrFail();

        return new PlaylistResource($playlist);
    }

    #[Get('{playlist}', 'api.playlist.show', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function show(Playlist $playlist)
    {
        $this->authorize('view', $playlist);

        $playlist->loadMissing('cover', 'songs');

        return new PlaylistResource($playlist);
    }

    #[Put('{playlist}', 'api.playlist.update', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function update(UpdatePlaylistRequest $request, Playlist $playlist)
    {
        $this->authorize('update', $playlist);

        $playlist->update([
            'name'        => $request->get('name'),
            'description' => $request->get('description'),
            'is_public'   => $request->boolean('is_public'),
        ]);

        return new PlaylistResource($playlist);
    }

    #[Delete('{playlist}', 'api.playlist.destroy', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function destroy(Playlist $playlist)
    {
        $this->authorize('delete', $playlist);

        $playlist->delete();

        return response()->noContent();
    }

    #[Post('{playlist}/songs/{song}', 'api.playlist.add-song', ['auth:sanctum',
                                                                'ability:' . TokenAbility::ACCESS_API->value])]
    public function addSong(Playlist $playlist, Song $song)
    {
        $this->authorize('update', $playlist);

        // Prevent duplicate songs in playlist
        if (!$playlist->songs()->where('song_id', $song->id)->exists()) {
            $position = $playlist->songs()->max('position') + 1;
            $playlist->songs()->attach($song, ['position' => $position]);
        }

        return response()->json(['message' => 'Song added to playlist']);
    }

    #[Delete('{playlist}/songs/{song}', 'api.playlist.remove-song', ['auth:sanctum',
                                                                     'ability:' . TokenAbility::ACCESS_API->value])]
    public function removeSong(Playlist $playlist, Song $song)
    {
        $this->authorize('update', $playlist);

        $playlist->songs()->detach($song);

        // Reorder remaining songs
        $playlist->songs()
            ->get()
            ->each(function ($song, $index) {
                $song->pivot->update(['position' => $index + 1]);
            });

        return response()->json(['message' => 'Song removed from playlist']);
    }

    #[Post('{playlist}/reorder', 'api.playlist.reorder', ['auth:sanctum',
                                                          'ability:' . TokenAbility::ACCESS_API->value])]
    public function reorderSongs(Request $request, Playlist $playlist)
    {
        $this->authorize('update', $playlist);

        $request->validate([
            'song_ids'   => 'required|array',
            'song_ids.*' => 'exists:songs,id',
        ]);

        $songIds = $request->song_ids;

        foreach ($songIds as $index => $songId) {
            $playlist->songs()->updateExistingPivot($songId, [
                'position' => $index + 1,
            ]);
        }

        return response()->json(['message' => 'Playlist reordered']);
    }

    #[Post('{playlist}/collaborators', 'api.playlist.collaborators.store', ['auth:sanctum',
                                                                            'ability:' . TokenAbility::ACCESS_API->value])]
    public function addCollaborator(Request $request, Playlist $playlist)
    {
        $this->authorize('update', $playlist);

        $request->validate([
            'user_id' => 'required|exists:users,id',
            'role'    => 'sometimes|in:editor,contributor',
        ]);

        $playlist->collaborators()->syncWithoutDetaching([
            $request->user_id => ['role' => $request->role ?? 'contributor'],
        ]);

        return response()->json(['message' => 'Collaborator added']);
    }

    #[Delete('{playlist}/collaborators/{user}', 'api.playlist.collaborators.destroy', ['auth:sanctum',
                                                                                       'ability:' . TokenAbility::ACCESS_API->value])]
    public function removeCollaborator(Playlist $playlist, User $user)
    {
        $this->authorize('update', $playlist);

        $playlist->collaborators()->detach($user);

        return response()->json(['message' => 'Collaborator removed']);
    }

    #[Post('{playlist}/clone', 'api.playlist.clone', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function clone(Playlist $playlist)
    {
        $this->authorize('view', $playlist);

        $newPlaylist = $playlist->replicate();
        $newPlaylist->name = $playlist->name . ' (Copy)';
        $newPlaylist->user_id = Auth::id();
        $newPlaylist->is_public = false;
        $newPlaylist->is_collaborative = false;
        $newPlaylist->save();

        // Copy songs with their positions
        $songs = $playlist->songs()->get();
        $songData = $songs->mapWithKeys(function ($song) {
            return [$song->id => ['position' => $song->pivot->position]];
        });

        $newPlaylist->songs()->sync($songData);

        return new PlaylistResource($newPlaylist);
    }

    #[Get('{playlist}/statistics', 'api.playlist.statistics', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function statistics(Playlist $playlist)
    {
        $this->authorize('view', $playlist);

        $statistic = $playlist->statistics()->first();

        return new PlaylistStatistic($statistic);
    }

    #[Post('{playlist}/statistics/record/view', 'api.playlist.statistics.record.view', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function recordView(Playlist $playlist)
    {
        $this->authorize('view', $playlist);

        $playlist->incrementViews();

        return response()->json(['message' => 'View recorded']);
    }

    #[Post('{playlist}/statistics/record/play', 'api.playlist.statistics.record.view', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function recordPlay(Playlist $playlist)
    {
        $this->authorize('view', $playlist);

        $playlist->incrementPlays();

        return response()->json(['message' => 'Play recorded']);
    }

    #[Post('{playlist}/statistics/record/share', 'api.playlist.statistics.record.view', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function share(Playlist $playlist)
    {
        $this->authorize('view', $playlist);

        $playlist->incrementShares();

        return response()->json(['message' => 'Share recorded']);
    }

    #[Post('{playlist}/statistics/record/favorite', 'api.playlist.statistics.record.view', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function favorite(Playlist $playlist)
    {
        $this->authorize('view', $playlist);

        $playlist->incrementFavorites();

        return response()->json(['message' => 'Favorite recorded']);
    }

    #[Post('{playlist}/smart', 'api.playlist.smart', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function createSmartPlaylist(CreateSmartPlaylistRequest $request)
    {
        $this->authorize('create', Playlist::class);

        $playlist = new Playlist([
            'name'        => $request->name,
            'description' => $request->description,
            'is_public'   => $request->boolean('is_public'),
            'is_smart'    => true,
            'smart_rules' => $request->rules,
        ]);
        $playlist->user()->associate($request->user());
        $playlist->saveOrFail();

        $playlist->syncSmartPlaylist();

        return new PlaylistResource($playlist);
    }

    #[Put('{playlist}/smart', 'api.playlist.smart', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function updateSmartRules(UpdateSmartPlaylistRulesRequest $request, Playlist $playlist)
    {
        $this->authorize('update', $playlist);

        if (!$playlist->is_smart) {
            abort(400, 'Not a smart playlist');
        }

        $playlist->update(['smart_rules' => $request->get('rules')]);
        $playlist->syncSmartPlaylist();

        return new PlaylistResource($playlist);
    }

    #[Post('{playlist}/smart/sync', 'api.playlist.smart', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function syncSmartPlaylist(Playlist $playlist)
    {
        $this->authorize('update', $playlist);

        if (!$playlist->is_smart) {
            abort(400, 'Not a smart playlist');
        }

        $playlist->syncSmartPlaylist();

        return response()->json(['message' => 'Smart playlist synced']);
    }
}
