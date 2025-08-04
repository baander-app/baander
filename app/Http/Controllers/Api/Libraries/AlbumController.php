<?php

namespace App\Http\Controllers\Api\Libraries;

use App\Http\Controllers\Controller;
use App\Http\Requests\Album\AlbumIndexRequest;
use App\Http\Requests\Album\AlbumUpdateRequest;
use App\Http\Resources\Album\AlbumResource;
use App\Models\{Album, Library, TokenAbility};
use App\Modules\{Http\Pagination\JsonPaginator};
use App\Modules\Eloquent\BaseBuilder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\RouteAttributes\Attributes\{Get, Middleware, Prefix, Put};

#[Prefix('/libraries/{library}/albums')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class AlbumController extends Controller
{
    /**
     * Get a collection of albums
     *
     * @param Library $library
     * @param AlbumIndexRequest $request
     * @return AnonymousResourceCollection<JsonPaginator<AlbumResource>>
     */
    #[Get('/', 'api.albums.index')]
    public function index(Library $library, AlbumIndexRequest $request)
    {
        $fields = $request->query('fields');
        $relations = $request->query('relations');
        $genres = $request->query('genres');

        $albums = (new \App\Models\Album)->query()
            ->selectFields(Album::$filterFields, $fields)
            ->withRelations(Album::$filterRelations, $relations)
            ->when($relations, function (BaseBuilder $q) use ($relations) {
                return $q->with(explode(',', $relations));
            })->when($fields, function (BaseBuilder $query) use ($fields) {
                $fields = array_merge(explode(',', $fields));

                return $query->select($fields);
            })->when($genres, function (BaseBuilder $q) use ($genres) {
                $q->whereGenreNames($genres);
            })
            ->paginate();

        $albums->each(function (Album $album) use ($library) {
            $album->setRelation('library', $library);
        });

        return AlbumResource::collection($albums);
    }

    /**
     * Get an album
     *
     * @param Library $library
     * @param Album $album
     * @return AlbumResource
     */
    #[Get('{album}', 'api.albums.show')]
    public function show(Library $library, Album $album)
    {
        $album->setRelation('library', $library);
        $album->loadMissing(['artists', 'cover', 'songs']);

        return new AlbumResource($album);
    }

    #[Put('{album}', 'api.albums.update')]
    public function update(Library $library, Album $album, AlbumUpdateRequest $request)
    {
        $album->setRelation('library', $library);
        $album->update($request->validated());

        return new AlbumResource($album);
    }
}