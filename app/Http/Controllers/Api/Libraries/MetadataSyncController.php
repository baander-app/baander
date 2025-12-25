<?php

namespace App\Http\Controllers\Api\Libraries;

use App\Http\Controllers\Controller;

use App\Modules\Metadata\MetadataJobDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\RouteAttributes\Attributes\{Middleware, Post, Get, Prefix};

#[Prefix('/metadata')]
#[Middleware([
    'auth:oauth',
    'scope:access-api',
    'force.json',
])]
class MetadataSyncController extends Controller
{
    public function __construct(
        private readonly MetadataJobDispatcher $metadataSyncService
    ) {}

    #[Post('/sync', 'api.metadata.sync')]
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'album_public_ids'       => 'sometimes|array',
            'album_public_ids.*'     => 'string|exists:albums,public_id',
            'song_public_ids'        => 'sometimes|array',
            'song_public_ids.*'      => 'string|exists:songs,public_id',
            'artist_public_ids'      => 'sometimes|array',
            'artist_public_ids.*'    => 'string|exists:artists,public_id',
            'library_id'      => 'sometimes|integer|exists:libraries,id',
            'include_songs'   => 'sometimes|boolean',
            'include_artists' => 'sometimes|boolean',
            'force_update'    => 'sometimes|boolean',
            'batch_size'      => 'sometimes|integer|min:1|max:100',
            'queue'           => 'sometimes|string',
        ]);

        // Convert public_ids to database IDs
        $albumIds = [];
        if (!empty($validated['album_public_ids'])) {
            $albumIds = \App\Models\Album::whereIn('public_id', $validated['album_public_ids'])
                ->pluck('id')
                ->toArray();
        }

        $songIds = [];
        if (!empty($validated['song_public_ids'])) {
            $songIds = \App\Models\Song::whereIn('public_id', $validated['song_public_ids'])
                ->pluck('id')
                ->toArray();
        }

        $artistIds = [];
        if (!empty($validated['artist_public_ids'])) {
            $artistIds = \App\Models\Artist::whereIn('public_id', $validated['artist_public_ids'])
                ->pluck('id')
                ->toArray();
        }

        $libraryId = $validated['library_id'] ?? null;

        if (!$libraryId && empty($albumIds) && empty($songIds) && empty($artistIds)) {
            throw ValidationException::withMessages([
                'sync' => 'You must specify at least one of: album_public_ids, song_public_ids, artist_public_ids, or library_id',
            ]);
        }

        $totalJobs = $this->metadataSyncService->syncMixed(
            $albumIds,
            $songIds,
            $artistIds,
            $libraryId,
            $validated['force_update'] ?? false,
            $validated['batch_size'] ?? null,
            $validated['queue'] ?? null,
            $validated['include_songs'] ?? false,
            $validated['include_artists'] ?? false,
        );

        return response()->json([
            'message'      => 'Metadata sync jobs queued successfully',
            'jobs_queued'  => $totalJobs,
            'sync_details' => [
                'albums'          => count($albumIds),
                'songs'           => count($songIds),
                'artists'         => count($artistIds),
                'library_id'      => $libraryId,
                'include_songs'   => $validated['include_songs'] ?? false,
                'include_artists' => $validated['include_artists'] ?? false,
            ],
        ]);
    }

    #[Get('/stats/{libraryId}', 'api.metadata.stats')]
    public function getLibraryStats(Request $request, int $libraryId): JsonResponse
    {
        $stats = $this->metadataSyncService->getLibraryStats($libraryId);

        return response()->json([
            'library_id' => $libraryId,
            'stats'      => $stats,
        ]);
    }

    #[Post('/validate', 'api.metadata.validate')]
    public function validateIds(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'album_public_ids'    => 'sometimes|array',
            'album_public_ids.*'  => 'string',
            'song_public_ids'     => 'sometimes|array',
            'song_public_ids.*'   => 'string',
            'artist_public_ids'   => 'sometimes|array',
            'artist_public_ids.*' => 'string',
        ]);

        // Convert public_ids to database IDs for validation
        $albumIds = [];
        if (!empty($validated['album_public_ids'])) {
            $albumIds = \App\Models\Album::whereIn('public_id', $validated['album_public_ids'])
                ->pluck('id')
                ->toArray();
        }

        $songIds = [];
        if (!empty($validated['song_public_ids'])) {
            $songIds = \App\Models\Song::whereIn('public_id', $validated['song_public_ids'])
                ->pluck('id')
                ->toArray();
        }

        $artistIds = [];
        if (!empty($validated['artist_public_ids'])) {
            $artistIds = \App\Models\Artist::whereIn('public_id', $validated['artist_public_ids'])
                ->pluck('id')
                ->toArray();
        }

        $validation = $this->metadataSyncService->validateIds(
            $albumIds,
            $songIds,
            $artistIds,
        );

        return response()->json([
            'validation_results' => $validation,
        ]);
    }
}
