<?php

namespace App\Http\Controllers\Api;

use App\Extensions\{JsonPaginator};
use App\Extensions\BaseBuilder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Album\AlbumIndexRequest;
use App\Http\Resources\Album\AlbumResource;
use App\Models\{Album, Library, TokenAbility};
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\RouteAttributes\Attributes\{Get, Middleware, Prefix};

#[Prefix('/libraries/{library}/albums')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class AlbumController extends Controller
{
    /**
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
        if ($genres) {
            $genres = explode(',', $genres);
        }

        $albums = Album::query()
            ->when($relations, function (BaseBuilder $q) use ($relations) {
                return $q->with(explode(',', $relations));
            })->when($fields, function (BaseBuilder $query) use ($fields) {
                $fields = array_merge(explode(',', $fields));

                return $query->select($fields);
            })->when($genres, function (BaseBuilder $q) use ($genres) {
                $q->whereHas('songs', function ($q) use ($genres) {
                    $q->whereHas('genres', function ($q) use ($genres) {
                        $q->whereIn('name', $genres);
                    });
                });
            })
            ->paginate($request->query('perPage', 60));

        $albums->each(function (Album $album) use ($library) {
            $album->setRelation('library', $library);
        });

        return AlbumResource::collection($albums);
    }

    #[Get('{album}', 'api.albums.show')]
    public function show(Library $library, Album $album)
    {
        $album->setRelation('library', $library);
        $album->loadMissing(['albumArtist', 'cover', 'songs']);

        return new AlbumResource($album);
    }
}