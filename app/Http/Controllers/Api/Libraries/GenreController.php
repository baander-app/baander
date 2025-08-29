<?php

namespace App\Http\Controllers\Api\Libraries;

use App\Http\Controllers\Controller;
use App\Http\Requests\Genre\{GenreIndexRequest, UpdateGenreRequest};
use App\Http\Resources\Genre\GenreResource;
use App\Models\{Genre, TokenAbility};
use App\Modules\Eloquent\BaseBuilder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Response;
use Spatie\RouteAttributes\Attributes\{Delete, Get, Middleware, Patch, Prefix};

#[Prefix('/genres')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class GenreController extends Controller
{
    /**
     * Get a paginated collection of music genres
     *
     * Returns a filtered and paginated list of all music genres in the system.
     * Supports field selection and library filtering for optimized queries.
     *
     * @param GenreIndexRequest $request Request with filtering and pagination parameters
     */
    #[Get('/', 'api.genres.index')]
    public function index(GenreIndexRequest $request)
    {
        /** @var string|null $fields Comma-separated list of fields to select */
        $fields = $request->query('fields');
        $librarySlug = $request->query('librarySlug');

        $genres = Genre::query()
//            ->selectFields(Genre::$filterFields, $fields)
            ->withRelations(Genre::$filterFields, $fields)
            ->paginate();

        return GenreResource::collection($genres);
    }

    /**
     * Get a specific genre with detailed information
     *
     * Retrieves a single genre with comprehensive information including
     * associated artists, albums, and usage statistics.
     *
     * @param Genre $genre The genre to retrieve
     *
     * @throws ModelNotFoundException When genre is not found
     * @response GenreResource
     */
    #[Get('{genre}', 'api.genres.show')]
    public function show(Genre $genre)
    {
        return new GenreResource($genre);
    }

    /**
     * Update an existing genre
     *
     * Updates genre information including name, description, and metadata.
     * Only the fields included in the request will be modified.
     *
     * @param UpdateGenreRequest $request Request containing validated update data
     * @param Genre $genre The genre to update
     *
     * @throws ModelNotFoundException When genre is not found
     * @response GenreResource
     */
    #[Patch('/{genre}', 'api.genres.update')]
    public function update(UpdateGenreRequest $request, Genre $genre)
    {
        $genre->update($request->validated());

        return new GenreResource($genre);
    }

    /**
     * Delete a genre
     *
     * Permanently removes a genre from the system. This action will also
     * remove the genre association from all related albums and songs.
     *
     * @param Genre $genre The genre to delete
     *
     * @throws ModelNotFoundException When genre is not found
     * @status 204
     */
    #[Delete('/{genre}', 'api.genres.destroy')]
    public function delete(Genre $genre)
    {
        $genre->delete();

        return response(null, Response::HTTP_NO_CONTENT);
    }
}
