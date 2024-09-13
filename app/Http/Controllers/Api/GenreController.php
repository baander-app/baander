<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Genre\UpdateGenreRequest;
use App\Http\Requests\Genres\GenreIndexRequest;
use App\Http\Resources\Genre\GenreResource;
use App\Models\{Genre, TokenAbility};
use Spatie\RouteAttributes\Attributes\{Delete, Get, Middleware, Patch, Prefix};

#[Prefix('/genres')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class GenreController
{
    /**
     * Get a collection of genres
     */
    #[Get('/', 'api.genres.index')]
    public function index(GenreIndexRequest $request)
    {
        $fields = $request->query('fields');

        $genres = Genre::query()
            ->selectFields(Genre::$filterFields, $fields)
            ->withRelations(Genre::$filterFields, $fields)
            ->paginate($request->query('perPage'));

        return GenreResource::collection($genres);
    }

    /**
     * Get a genre
     */
    #[Get('{genre}', 'api.genres.show')]
    public function show(Genre $genre)
    {
        return new GenreResource($genre);
    }

    /**
     * Update a genre
     */
    #[Patch('/{genre}', 'api.genres.update')]
    public function update(UpdateGenreRequest $request, Genre $genre)
    {
        $genre->update($request->validated());

        return new GenreResource($genre);
    }

    /**
     * Delete a genre
     */
    #[Delete('/{genre}', 'api.genres.destroy')]
    public function delete(Genre $genre)
    {
        $genre->delete();

        return response(null, 204);
    }
}