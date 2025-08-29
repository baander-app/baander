<?php

namespace App\Http\Controllers\Api\Libraries;

use App\Http\Controllers\Controller;
use App\Http\Resources\Movie\MovieResource;
use App\Models\Library;
use App\Models\Movie;
use App\Models\TokenAbility;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Prefix;

#[Prefix('/libraries/{library}/movies')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class MovieController extends Controller
{
    /**
     * Get a paginated collection of movies from a specific library
     *
     * Returns a paginated list of all movies contained within the specified video library.
     * Movies are filtered by the library to ensure only content from the requested library is returned.
     *
     * @param Library $library The video library to retrieve movies from
     *
     * @response AnonymousResourceCollection<JsonPaginator<MovieResource>>
     */
    #[Get('/', 'api.movies.index')]
    public function index(Library $library): AnonymousResourceCollection
    {
        $movies = Movie::query()
            ->whereLibraryId($library->id)
            ->with(['genres', 'cast', 'crew']) // Load common relations for better performance
            ->paginate();

        return MovieResource::collection($movies);
    }

    /**
     * Get a specific movie with detailed information
     *
     * Retrieves a single movie from the specified library with comprehensive
     * information including cast, crew, genres, technical details, and metadata.
     *
     * @param Library $library The library containing the movie
     * @param Movie $movie The movie to retrieve
     *
     * @throws ModelNotFoundException When a movie is not found in the library
     * @response MovieResource
     */
    #[Get('{movie}', 'api.movies.show')]
    public function show(Library $library, Movie $movie): MovieResource
    {
        return new MovieResource($movie);
    }
}
