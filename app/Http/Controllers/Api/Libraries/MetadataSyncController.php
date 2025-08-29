<?php

namespace App\Http\Controllers\Api\Libraries;

use App\Http\Controllers\Controller;
use App\Models\TokenAbility;
use App\Modules\Metadata\MetadataJobDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Prefix;
use Symfony\Component\Routing\Attribute\Route;

#[Prefix('/metadata')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class MetadataSyncController extends Controller
{
    public function __construct(
        private readonly MetadataJobDispatcher $metadataSyncService
    ) {}

    #[Route('/sync', 'api.metadata.sync')]
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'album_ids'       => 'sometimes|array',
            'album_ids.*'     => 'integer|exists:albums,id',
            'song_ids'        => 'sometimes|array',
            'song_ids.*'      => 'integer|exists:songs,id',
            'artist_ids'      => 'sometimes|array',
            'artist_ids.*'    => 'integer|exists:artists,id',
            'library_id'      => 'sometimes|integer|exists:libraries,id',
            'include_songs'   => 'sometimes|boolean',
            'include_artists' => 'sometimes|boolean',
            'force_update'    => 'sometimes|boolean',
            'batch_size'      => 'sometimes|integer|min:1|max:100',
            'queue'           => 'sometimes|string',
        ]);

        $albumIds = $validated['album_ids'] ?? [];
        $songIds = $validated['song_ids'] ?? [];
        $artistIds = $validated['artist_ids'] ?? [];
        $libraryId = $validated['library_id'] ?? null;

        if (!$libraryId && empty($albumIds) && empty($songIds) && empty($artistIds)) {
            throw ValidationException::withMessages([
                'sync' => 'You must specify at least one of: album_ids, song_ids, artist_ids, or library_id',
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

    #[Route('/stats', 'api.metadata.stats')]
    public function getLibraryStats(Request $request, int $libraryId): JsonResponse
    {
        $stats = $this->metadataSyncService->getLibraryStats($libraryId);

        return response()->json([
            'library_id' => $libraryId,
            'stats'      => $stats,
        ]);
    }

    #[Route('/validate', 'api.metadata.validate')]
    public function validateIds(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'album_ids'    => 'sometimes|array',
            'album_ids.*'  => 'integer',
            'song_ids'     => 'sometimes|array',
            'song_ids.*'   => 'integer',
            'artist_ids'   => 'sometimes|array',
            'artist_ids.*' => 'integer',
        ]);

        $validation = $this->metadataSyncService->validateIds(
            $validated['album_ids'] ?? [],
            $validated['song_ids'] ?? [],
            $validated['artist_ids'] ?? [],
        );

        return response()->json([
            'validation_results' => $validation,
        ]);
    }
}
