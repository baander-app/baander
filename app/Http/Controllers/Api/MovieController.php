<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\Movie\MovieResource;
use App\Models\Library;
use App\Models\Movie;
use App\Models\TokenAbility;
use App\Modules\Pagination\JsonPaginator;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Prefix;

#[Prefix('/libraries/{library}/movies')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class MovieController
{
    /**
     * Get a collection of movies
     *
     * @param Library $library
     * @return AnonymousResourceCollection<JsonPaginator<MovieResource>>
     */
    #[Get('/', 'api.movies.index')]
    public function index(Library $library)
    {
        $movies = Movie::query()
            ->whereLibraryId($library->id)
            ->paginate();

        return MovieResource::collection($movies);
    }

    /**
     * Get a movie
     *
     * @param Library $library
     * @param Movie $movie
     * @return MovieResource
     */
    #[Get('{movie}', 'api.movies.show')]
    public function show(Library $library, Movie $movie)
    {
        return new MovieResource($movie);
    }
}