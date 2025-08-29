<?php

namespace App\Http\Controllers\Api\Libraries;

use App\Http\Controllers\Controller;
use App\Modules\Http\Pagination\JsonPaginator;
use App\Http\Requests\Playlist\{CreatePlaylistRequest,
    CreateSmartPlaylistRequest,
    PlaylistShowRequest,
    UpdatePlaylistRequest,
    UpdateSmartPlaylistRulesRequest};
use App\Http\Resources\Playlist\PlaylistResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use App\Models\{Playlist, PlaylistStatistic, Song, TokenAbility, User};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Spatie\RouteAttributes\Attributes\{Delete, Get, Middleware, Post, Prefix, Put};
use Throwable;

#[Middleware(['force.json'])]
#[Prefix('/playlists')]
class PlaylistController extends Controller
{
    /**
     * Get a paginated collection of playlists
     *
     * Returns playlists owned by the authenticated user and public playlists
     * that are visible to all users. Results are paginated for performance.
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

    /**
     * Create a new playlist
     *
     * Creates a new playlist owned by the authenticated user with the provided
     * name, description, and visibility settings.
     *
     * @param CreatePlaylistRequest $request Request containing validated playlist data
     *
     * @throws Throwable When playlist creation fails
     * @response PlaylistResource
     * @status 201
     */
    #[Post('', 'api.playlist.store', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function store(CreatePlaylistRequest $request)
    {
        $playlist = new Playlist([
            'name'        => $request->get('name'),
            'description' => $request->get('description'),
            'is_public'   => $request->boolean('isPublic'),
        ]);

        $playlist->user()->associate($request->user());
        $playlist->saveOrFail();

        return new PlaylistResource($playlist);
    }

    /**
     * Get a specific playlist with detailed information
     *
     * Retrieves a single playlist with comprehensive information including
     * songs, artists, album data, and cover art. Authorization is enforced.
     *
     * @param PlaylistShowRequest $request Request for playlist access
     * @param Playlist $playlist The playlist to retrieve
     *
     * @throws AuthorizationException When a user cannot view a playlist
     * @throws ModelNotFoundException When a playlist is not found
     * @response PlaylistResource
     */
    #[Get('{playlist}', 'api.playlist.show', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function show(PlaylistShowRequest $request, Playlist $playlist)
    {
        $this->authorize('view', $playlist);

        $playlist->loadMissing('cover', 'songs', 'songs.artists', 'songs.album');

        return new PlaylistResource($playlist);
    }

    /**
     * Delete a playlist
     *
     * Permanently removes a playlist and all its associated data including
     * song associations, statistics, and collaborator relationships.
     *
     * @param Playlist $playlist The playlist to delete
     *
     * @throws AuthorizationException When user cannot delete playlist
     * @throws ModelNotFoundException When playlist is not found
     * @status 204
     */
    #[Delete('{playlist}', 'api.playlist.destroy', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function destroy(Playlist $playlist)
    {
        $this->authorize('delete', $playlist);

        $playlist->delete();

        return response()->noContent();
    }

    /**
     * Add a song to a playlist
     *
     * Adds a song to the specified playlist at the next available position.
     * Prevents duplicate songs from being added to the same playlist.
     *
     * @param Playlist $playlist The playlist to add the song to
     * @param Song $song The song to add
     *
     * @throws AuthorizationException When a user cannot update a playlist
     * @throws ModelNotFoundException When a playlist or song is not found
     * @response array{message: string}
     */
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

    /**
     * Remove a song from a playlist
     *
     * Removes a song from the playlist and automatically reorders remaining
     * songs to maintain consecutive positioning.
     *
     * @param Playlist $playlist The playlist to remove the song from
     * @param Song $song The song to remove
     *
     * @throws AuthorizationException When a user cannot update a playlist
     * @throws ModelNotFoundException When a playlist or song is not found
     * @response array{message: string}
     */
    #[Delete('{playlist}/songs/{song}', 'api.playlist.remove-song', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value])
    ]
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

    /**
     * Update an existing playlist
     *
     * Updates playlist metadata including name, description, and visibility settings.
     * Only playlist owners and authorized collaborators can update playlists.
     *
     * @param UpdatePlaylistRequest $request Request containing validated update data
     * @param Playlist $playlist The playlist to update
     *
     * @throws AuthorizationException When a user cannot update a playlist
     * @throws ModelNotFoundException When a playlist is not found
     * @response PlaylistResource
     */
    #[Put('{playlist}', 'api.playlist.update', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function update(UpdatePlaylistRequest $request, Playlist $playlist): PlaylistResource
    {
        $this->authorize('update', $playlist);

        $playlist->update([
            'name'        => $request->get('name'),
            'description' => $request->get('description'),
            'is_public'   => $request->boolean('isPublic'),
        ]);

        $playlist->refresh(['user', 'cover']);

        return new PlaylistResource($playlist);
    }

    /**
     * Reorder songs in a playlist
     *
     * Updates the position of songs in the playlist based on the provided
     * ordered array of song IDs. All song IDs must exist in the playlist.
     *
     * @param Request $request Request containing ordered song IDs
     * @param Playlist $playlist The playlist to reorder
     *
     * @throws AuthorizationException When user cannot update playlist
     * @throws ValidationException When song IDs are invalid
     * @response array{message: string}
     */
    #[Post('{playlist}/reorder', 'api.playlist.reorder', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value])
    ]
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

    /**
     * Add a collaborator to a playlist
     *
     * Adds a user as a collaborator to the playlist with the specified role.
     * Collaborators can have 'editor' or 'contributor' permissions.
     *
     * @param Request $request
     * @param Playlist $playlist
     * @return JsonResponse
     */
    #[Post('{playlist}/collaborators', 'api.playlist.collaborators.store', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value])
    ]
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

    /**
     * Remove a collaborator from a playlist
     *
     * Removes a user's collaborator access from the playlist.
     * Only playlist owners can remove collaborators.
     *
     * @param Playlist $playlist
     * @param User $user
     * @return JsonResponse
     */
    #[Delete('{playlist}/collaborators/{user}', 'api.playlist.collaborators.destroy', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value])
    ]
    public function removeCollaborator(Playlist $playlist, User $user)
    {
        $this->authorize('update', $playlist);

        $playlist->collaborators()->detach($user);

        return response()->json(['message' => 'Collaborator removed']);
    }

    /**
     * Clone an existing playlist
     *
     * Creates a copy of the playlist with all songs and their positions.
     * The cloned playlist is owned by the current user and is private by default.
     *
     * @param Playlist $playlist
     * @return PlaylistResource
     */
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

    /**
     * Get playlist statistics
     *
     * Retrieves comprehensive statistics for the playlist including
     * view count, play count, shares, and favorites.
     *
     * @param Playlist $playlist
     * @return PlaylistStatistic
     */
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

    /**
     * Record a playlist view
     *
     * @param Playlist $playlist
     * @return JsonResponse
     */
    #[Post('{playlist}/statistics/record/view', 'api.playlist.statistics.record-view', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function recordView(Playlist $playlist)
    {
        $this->authorize('view', $playlist);

        $playlist->incrementViews();

        return response()->json(['message' => 'View recorded']);
    }

    /**
     * Record a playlist play
     *
     * Increments the play counter when the playlist is played.
     * Used for tracking playlist engagement and popularity metrics.
     *
     * @param Playlist $playlist The playlist that was played
     *
     * @throws AuthorizationException When a user cannot view a playlist
     * @response array{message: string}
     */
    #[Post('{playlist}/statistics/record/play', 'api.playlist.statistics.record-play', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function recordPlay(Playlist $playlist)
    {
        $this->authorize('view', $playlist);

        $playlist->incrementPlays();

        return response()->json(['message' => 'Play recorded']);
    }

    /**
     * Record a playlist share
     *
     * Increments the share counter when the playlist is shared.
     * Used for tracking viral and social engagement metrics.
     *
     * @param Playlist $playlist The playlist that was shared
     *
     * @throws AuthorizationException When user cannot view playlist
     * @response array{message: string}
     */
    #[Post('{playlist}/statistics/record/share', 'api.playlist.statistics.record-share', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function share(Playlist $playlist)
    {
        $this->authorize('view', $playlist);

        $playlist->incrementShares();

        return response()->json(['message' => 'Share recorded']);
    }

    /**
     * Record a playlist favorite
     *
     * Increments the favorite counter when users mark the playlist as favorite.
     * Used for tracking user engagement and playlist quality metrics.
     *
     * @param Playlist $playlist The playlist that was favorited
     *
     * @throws AuthorizationException When user cannot view playlist
     * @response array{message: string}
     */
    #[Post('{playlist}/statistics/record/favorite', 'api.playlist.statistics.record-favorite', [
        'auth:sanctum',
        'ability:' . TokenAbility::ACCESS_API->value,
    ])]
    public function favorite(Playlist $playlist)
    {
        $this->authorize('view', $playlist);

        $playlist->incrementFavorites();

        return response()->json(['message' => 'Favorite recorded']);
    }

    /**
     * Create a smart playlist
     *
     * Creates a new smart playlist that automatically populates with songs
     * matching the specified rules and criteria.
     *
     * @param CreateSmartPlaylistRequest $request Request containing playlist data and rules
     *
     * @throws Throwable When smart playlist creation fails
     * @response PlaylistResource
     * @status 201
     */
    #[Post('/smart', 'api.playlist.smart-create', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function createSmartPlaylist(CreateSmartPlaylistRequest $request)
    {
//        $this->authorize('create', Playlist::class);

        $playlist = new Playlist([
            'name'        => $request->name,
            'description' => $request->description,
            'is_public'   => $request->boolean('isPublic'),
            'is_smart'    => true,
            'smart_rules' => $request->rules,
        ]);
        $playlist->user()->associate($request->user());
        $playlist->saveOrFail();

        $playlist->syncSmartPlaylist();

        return new PlaylistResource($playlist);
    }

    /**
     * Synchronize smart playlist
     *
     * Manually triggers a sync of the smart playlist to refresh the song list
     * based on the current rules and available songs in the library.
     *
     * @param UpdateSmartPlaylistRulesRequest $request
     * @param Playlist $playlist
     * @return PlaylistResource
     */
    #[Put('{playlist}/smart', 'api.playlist.smart-update', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
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

    /**
     * Update smart playlist rules
     *
     * Updates the rules for a smart playlist and re-syncs the song list
     * to match the new criteria.
     *
     * @param Playlist $playlist
     * @return JsonResponse
     */
    #[Post('{playlist}/smart/sync', 'api.playlist.smart-sync', ['auth:sanctum',
                                                           'ability:' . TokenAbility::ACCESS_API->value])]
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
