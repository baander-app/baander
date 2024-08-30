<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Genres\GenreIndexRequest;
use App\Http\Resources\Genre\GenreResource;
use App\Support\JsonPaginator;
use App\Models\{Genre, TokenAbility};
use Spatie\RouteAttributes\Attributes\{Get, Middleware, Prefix};

#[Prefix('/libraries/{library}/genres')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class GenreController
{
    /**
     * @param GenreIndexRequest $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection<JsonPaginator<GenreResource>>
     */
    #[Get('/', 'api.genres.index')]
    public function index(GenreIndexRequest $request)
    {
        $genres = Genre::paginate($request->query('perPage', 15));

        return GenreResource::collection($genres);
    }
}