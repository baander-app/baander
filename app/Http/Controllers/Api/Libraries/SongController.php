<?php

namespace App\Http\Controllers\Api\Libraries;

use App\Http\Controllers\Controller;
use App\Http\Requests\Song\{SongIndexRequest, SongShowRequest};
use App\Http\Resources\Song\SongResource;
use App\Models\{Album, Library, Song, TokenAbility};
use App\Modules\{Http\Pagination\JsonPaginator};
use App\Modules\Http\Resources\Json\JsonAnonymousResourceCollection;
use Spatie\RouteAttributes\Attributes\{Get, Middleware, Prefix};

#[Middleware(['force.json'])]
#[Prefix('/libraries/{library}/songs')]
class SongController extends Controller
{
    /**
     * Get a collection of songs
     *
     * @param SongIndexRequest $request
     * @param Library $library
     * @param Album $album
     * @return JsonAnonymousResourceCollection<JsonPaginator<SongResource>>
     */
    #[Get('', 'api.songs.index', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function index(SongIndexRequest $request, Library $library)
    {
        $relations = $request->query('relations');
        $genreNames = $request->query('genreNames');
        $genreSlugs = $request->query('genreSlugs');

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
     * Get a song by public id
     *
     * @param SongShowRequest $request
     * @param Library $library
     * @param string $publicId
     * @return SongResource
     */
    #[Get('{publicId}', 'api.songs.show', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function show(SongShowRequest $request, Library $library, string $publicId)
    {
        $relations = $request->query('relations');

        $song = Song::query()->wherePublicId($publicId)
            ->withRelations(Song::$filterRelations, $relations)
            ->firstOrFail();

        $song->librarySlug = $library->slug;

        return new SongResource($song);
    }
}