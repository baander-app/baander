<?php

namespace App\Http\Controllers\Api;

use App\Extensions\JsonPaginator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Library\{CreateLibraryRequest, LibraryIndexRequest, UpdateLibraryRequest};
use App\Http\Resources\Library\LibraryResource;
use App\Models\{Library, TokenAbility};
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\RouteAttributes\Attributes\{Delete, Get, Middleware, Patch, Post, Prefix};

#[Prefix('/libraries')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class LibraryController extends Controller
{
    /**
     * Get a collection of media libraries
     *
     * @return AnonymousResourceCollection<JsonPaginator<LibraryResource>>
     */
    #[Get('/', 'api.libraries.index')]
    public function index(LibraryIndexRequest $request)
    {
        $libraries = Library::paginate();

        return LibraryResource::collection($libraries);
    }

    /**
     * Create a library
     */
    #[Post('/', 'api.library.create')]
    public function create(CreateLibraryRequest $request)
    {
        $data = $request->validated();

        $library = Library::create($data);

        return new LibraryResource($library);
    }

    /**
     * Show library
     *
     * @param string $slug
     * @return LibraryResource
     */
    #[Get('/{slug}', 'api.library.show')]
    public function show(string $slug)
    {
        $library = Library::whereSlug($slug)->firstOrFail();

        return new LibraryResource($library);
    }

    /**
     * Update a library specified by the provided slug.
     */
    #[Patch('/{slug}', 'api.library.update')]
    public function update(string $slug, UpdateLibraryRequest $request)
    {
        $library = Library::whereSlug($slug)->firstOrFail();

        $library->update($request->validated());

        return new LibraryResource($library);
    }

    /**
     * Delete a library
     */
    #[Delete('/:slug', 'api.library.delete')]
    public function destroy(string $slug)
    {
        Library::whereSlug($slug)->delete();

        return response(null, Response::HTTP_NO_CONTENT);
    }
}
