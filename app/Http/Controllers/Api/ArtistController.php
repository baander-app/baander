<?php

namespace App\Http\Controllers\Api;

use App\Extensions\JsonPaginator;
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
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection<JsonPaginator<ArtistResource>>
     */
    #[Get('/', 'api.artists.index')]
    public function index()
    {
        $data = Artist::paginate();

        return ArtistResource::collection($data);
    }

    #[Get('{artist}', 'api.artists.show')]
    public function show(Artist $artist)
    {
        return new ArtistResource($artist);
    }
}