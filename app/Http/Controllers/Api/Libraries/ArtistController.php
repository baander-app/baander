<?php

namespace App\Http\Controllers\Api\Libraries;

use App\Http\Controllers\Controller;
use App\Http\Requests\Artist\{ArtistIndexRequest, ArtistUpdateRequest};
use App\Http\Resources\Artist\ArtistResource;
use App\Models\{Album, Artist, Library};
use App\Modules\Eloquent\BaseBuilder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spatie\RouteAttributes\Attributes\{Get, Middleware, Prefix, Put};

#[Prefix('/libraries/{library}/artists')]
#[Middleware([
    'auth:oauth',
    'scope:access-api',
    'force.json',
])]
class ArtistController extends Controller
{
    /**
     * Get a paginated collection of artists from a specific library
     *
     * Returns a filtered and paginated list of artists from the specified library.
     * Supports field selection and relation inclusion for optimized queries.
     *
     * @param Library $library The library to retrieve artists from
     * @param ArtistIndexRequest $request Request with filtering and pagination parameters
     */
    #[Get('/', 'api.artists.index')]
    public function index(Library $library, ArtistIndexRequest $request)
    {
        $fields = $request->query('fields');
        $relations = $request->query('relations');

        $artists = Artist::query()
            ->selectFields(Artist::$filterFields, $fields)
            ->withRelations(Artist::$filterRelations, $relations)
            ->when($relations, function (BaseBuilder $q) use ($relations) {
                /** @var array<string> $relationsList */
                $relationsList = explode(',', $relations);
                return $q->with($relationsList);
            })->when($fields, function (BaseBuilder $query) use ($fields) {
                /** @var array<string> $fieldsList */
                $fieldsList = array_merge(explode(',', $fields));
                return $query->select($fieldsList);
            })
            ->paginate();

        $artists->each(function (Artist $artist) use ($library) {
            $artist->setRelation('library', $library);
        });

        return ArtistResource::collection($artists);
    }

    /**
     * Get a specific artist with detailed information
     *
     * Retrieves a single artist from the specified library with comprehensive
     * information including albums, songs, and other related data.
     *
     * @param Library $library The library containing the artist
     * @param Artist $artist The artist to retrieve
     *
     * @throws ModelNotFoundException When an artist is not found in the library
     * @response ArtistResource
     */
    #[Get('{artist}', 'api.artists.show')]
    public function show(Library $library, Artist $artist): ArtistResource
    {
        $artist->loadMissing(['albums', 'songs']);
        return new ArtistResource($artist);
    }

    /**
     * Update an existing artist
     *
     * Updates artist metadata and information using the provided data.
     * Only the fields included in the request will be modified.
     *
     * @param Library $library The library containing the artist
     * @param Artist $artist The artist to update
     * @param ArtistUpdateRequest $request Request containing validated update data
     *
     * @throws ModelNotFoundException When an artist is not found in the library
     * @response ArtistResource
     */
    #[Put('{artist}', 'api.artists.update', ['auth:oauth', 'scope:access-api', 'precognitive'])]
    public function update(Library $library, Artist $artist, ArtistUpdateRequest $request): ArtistResource
    {
        $artist->setRelation('library', $library);
        $artist->update($request->validated());

        return new ArtistResource($artist);
    }
}
