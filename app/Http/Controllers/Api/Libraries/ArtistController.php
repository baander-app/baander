<?php

namespace App\Http\Controllers\Api\Libraries;

use App\Http\Requests\Artist\ArtistIndexRequest;
use App\Http\Resources\Artist\ArtistResource;
use App\Models\{Artist, TokenAbility};
use App\Modules\Http\Pagination\JsonPaginator;
use Spatie\RouteAttributes\Attributes\{Get, Middleware, Prefix};
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Prefix('/libraries/{library}/artists')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class ArtistController
{
    /**
     * Get a collection of artists
     *
     * @return AnonymousResourceCollection<JsonPaginator<ArtistResource>>
     */
    #[Get('/', 'api.artists.index')]
    public function index(ArtistIndexRequest $request)
    {
        $fields = $request->query('fields');
        $relations = $request->query('relations');

        $data = (new \App\Models\Artist)->query()
            ->selectFields(Artist::$filterFields, $fields)
            ->withRelations(Artist::$filterRelations, $relations)
            ->paginate();

        return ArtistResource::collection($data);
    }

    /**
     * Get an artist
     *
     * @param Artist $artist
     * @return ArtistResource
     */
    #[Get('{artist}', 'api.artists.show')]
    public function show(Artist $artist)
    {
        return new ArtistResource($artist);
    }
}