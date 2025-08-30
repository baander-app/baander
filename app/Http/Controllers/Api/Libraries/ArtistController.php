<?php

namespace App\Http\Controllers\Api\Libraries;

use App\Http\Controllers\Controller;
use App\Http\Requests\Artist\ArtistIndexRequest;
use App\Http\Resources\Artist\ArtistResource;
use App\Models\{Album, Artist, Library, TokenAbility};
use App\Modules\Eloquent\BaseBuilder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spatie\RouteAttributes\Attributes\{Get, Middleware, Prefix};

#[Prefix('/libraries/{library}/artists')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
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
        return new ArtistResource($artist);
    }
}
