<?php

namespace App\Http\Controllers\Api\Libraries;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\Song\{SongIndexRequest, SongShowRequest};
use App\Http\Resources\Song\SongResource;
use App\Models\{Album, Library, Song, TokenAbility};
use App\Modules\Eloquent\BaseBuilder;
use App\Modules\Http\Resources\Json\JsonAnonymousResourceCollection;
use Spatie\RouteAttributes\Attributes\{Get, Middleware, Prefix};

#[Middleware(['force.json'])]
#[Prefix('/libraries/{library}/songs')]
class SongController extends Controller
{
    /**
     * Get a paginated collection of songs from a specific library
     *
     * Returns a filtered and paginated list of songs from the specified library.
     * Supports relation inclusion and genre filtering. Songs are ordered by album title
     * and track number for a consistent browsing experience.
     *
     * @param SongIndexRequest $request Request with filtering and pagination parameters
     * @param Library $library The library to retrieve songs from
     *
     * @throws ValidationException When both genreNames and genreSlugs are provided
     */
    #[Get('', 'api.songs.index', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function index(SongIndexRequest $request, Library $library): JsonAnonymousResourceCollection
    {
        $relations = $request->query('relations');
        $genreNames = $request->query('genreNames');
        $genreSlugs = $request->query('genreSlugs');

        // Prevent conflicting genre filter parameters
        abort_if(
            $genreNames && $genreSlugs,
            400,
            'You cannot search for genre names and slugs at the same time',
        );

        $songs = Song::query()
            ->withRelations(Song::$filterRelations, $relations)
            ->when($genreSlugs, function ($query) use ($library, $genreSlugs) {
                return $query->whereGenreSlugs($genreSlugs);
            })->when($genreNames, function ($query) use ($library, $genreNames) {
                return $query->whereGenreNames($genreNames);
            })
            ->orderBy(Album::select('title')->whereColumn('songs.album_id', 'albums.id'))
            ->orderBy('track')
            ->paginate();

        $songs->each(function (Song $song) use ($library) {
            $song->librarySlug = $library->slug;
        });

        return SongResource::collection($songs);
    }

    /**
     * Get a specific song by its public identifier
     *
     * Retrieves a single song from the specified library using its public ID.
     * Supports relation inclusion for comprehensive song information including
     * artists, album data, genres, and audio metadata.
     *
     * @param SongShowRequest $request Request with optional relation parameters
     * @param Library $library The library containing the song
     * @param string $publicId The public identifier of the song to retrieve
     *
     * @throws ModelNotFoundException When song is not found
     * @response SongResource
     */
    #[Get('{publicId}', 'api.songs.show', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function show(SongShowRequest $request, Library $library, string $publicId): SongResource
    {
        /** @var string|null $relations Comma-separated list of relations to include */
        $relations = $request->query('relations');

        $song = Song::wherePublicId($publicId)
            ->withRelations(Song::$filterRelations, $relations)
            ->firstOrFail();

        $song->librarySlug = $library->slug;

        return new SongResource($song);
    }
}
