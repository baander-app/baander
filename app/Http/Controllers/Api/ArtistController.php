<?php

namespace App\Http\Controllers\Api;

use App\Extensions\JsonPaginator;
use App\Http\Requests\Artist\ArtistIndexRequest;
use App\Http\Resources\Artist\ArtistResource;
use App\Models\{Artist, TokenAbility};
use Spatie\RouteAttributes\Attributes\{Get, Middleware, Prefix};

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
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection<JsonPaginator<ArtistResource>>
     */
    #[Get('/', 'api.artists.index')]
    public function index(ArtistIndexRequest $request)
    {
        $fields = $request->query('fields');
        $relations = $request->query('relations');

        $data = Artist::query()
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