<?php

namespace App\Http\Controllers\Api;

use App\Extensions\JsonPaginator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Song\SongIndexRequest;
use App\Models\{Album, Library, Song, TokenAbility};
use App\Http\Resources\Song\SongWithAlbumResource;
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
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection<JsonPaginator<SongWithAlbumResource>>
     */
    #[Get('', 'api.songs.index', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function index(SongIndexRequest $request, Library $library)
    {
        $genres = $request->query('genreIds');
        $albumId = $request->query('albumId');

        $songs = Song::when($albumId, function ($query) use ($library, $albumId) {
            return $query->where('album_id', $albumId);
        })->when($genres, function ($query) use ($library, $genres) {
            return $query->whereHas('genres', function ($query) use ($genres) {
                return $query->whereIn('genreables_id', explode(',', $genres));
            });
        });

        $songs = $songs->filterBy($request->query())->paginate($request->integer('perPage', 30));

        $songs->each(function (Song $song) use ($library) {
            $song->librarySlug = $library->slug;
        });

        return SongWithAlbumResource::collection($songs);
    }

    /**
     * Get a song
     *
     * @param Library $library
     * @param Song $song
     * @return SongWithAlbumResource
     */
    #[Get('{song}', 'api.songs.show', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_API->value])]
    public function show(Library $library, Song $song)
    {
        $song->with('album');

        $song->libraryId = $library->id;
        $song->librarySlug = $library->slug;

        return new SongWithAlbumResource($song);
    }

    /**
     * Direct stream the song
     *
     * Requires token with "access-stream"
     *
     * @param Song $song
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    #[Get('/stream/song/{song}/direct', 'api.songs.stream', ['auth:sanctum', 'ability:' . TokenAbility::ACCESS_STREAM->value])]
    public function directStream(Library $library, Song $song)
    {
        return response()->file($song->getPath());
    }
}