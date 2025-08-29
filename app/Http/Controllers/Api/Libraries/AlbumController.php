<?php

namespace App\Http\Controllers\Api\Libraries;

use App\Http\Controllers\Controller;
use App\Http\Requests\Album\AlbumIndexRequest;
use App\Http\Requests\Album\AlbumUpdateRequest;
use App\Http\Resources\Album\AlbumResource;
use App\Models\{Album, Library, TokenAbility};
use App\Modules\Eloquent\BaseBuilder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
     * Get a paginated collection of albums from a specific library
     *
     * Returns a filtered and paginated list of albums from the specified library.
     * Supports field selection, relation inclusion, and genre filtering for optimized queries.
     *
     * @param Library $library The library to retrieve albums from
     * @param AlbumIndexRequest $request Request with filtering and pagination parameters
     */
    #[Get('/', 'api.albums.index')]
    public function index(Library $library, AlbumIndexRequest $request)
    {
        /** @var string|null $fields Comma-separated list of fields to select */
        $fields = $request->query('fields');

        /** @var string|null $relations Comma-separated list of relations to include */
        $relations = $request->query('relations');

        /** @var string|null $genres Comma-separated list of genre names to filter by */
        $genres = $request->query('genres');

        $albums = Album::query()
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
     * Get a specific album with detailed information
     *
     * Retrieves a single album from the specified library with all related data
     * including artists, cover art, and songs for comprehensive display.
     *
     * @param Library $library The library containing the album
     * @param Album $album The album to retrieve
     *
     * @throws ModelNotFoundException When an album is not found in the library
     * @response AlbumResource
     */
    #[Get('{album}', 'api.albums.show')]
    public function show(Library $library, Album $album)
    {
        $album->setRelation('library', $library);
        $album->loadMissing(['artists', 'cover', 'songs']);

        return new AlbumResource($album);
    }

    /**
     * Update an existing album
     *
     * Updates album metadata and information using the provided data.
     * Only the fields included in the request will be modified.
     *
     * @param Library $library The library containing the album
     * @param Album $album The album to update
     * @param AlbumUpdateRequest $request Request containing validated update data
     *
     * @throws ModelNotFoundException When an album is not found in the library
     * @response AlbumResource
     */
    #[Put('{album}', 'api.albums.update')]
    public function update(Library $library, Album $album, AlbumUpdateRequest $request): AlbumResource
    {
        $album->setRelation('library', $library);
        $album->update($request->validated());

        return new AlbumResource($album);
    }
}
