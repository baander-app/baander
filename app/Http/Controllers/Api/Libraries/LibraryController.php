<?php

namespace App\Http\Controllers\Api\Libraries;

use App\Http\Controllers\Controller;
use App\Http\Requests\Library\{CreateLibraryRequest, LibraryIndexRequest, UpdateLibraryRequest};
use App\Http\Resources\Library\LibraryResource;
use App\Http\Resources\Library\LibraryStatsResource;
use App\Models\{Enums\MetaKey, Library, TokenAbility};
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Response;
use Spatie\RouteAttributes\Attributes\{Delete, Get, Middleware, Patch, Post, Prefix};
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

#[Prefix('/libraries')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class LibraryController extends Controller
{
    /**
     * Get a paginated collection of media libraries
     *
     * Returns a paginated list of all available media libraries with basic information.
     * Does not include detailed statistics - use the show endpoint for comprehensive data.
     */
    #[Get('/', 'api.libraries.index')]
    public function index(LibraryIndexRequest $request)
    {
        $libraries = (new Library)->paginate();

        // Paginated collection of library resources.
        return LibraryResource::collection($libraries);
    }


    /**
     * Create a new media library
     *
     * Creates a new library with the provided configuration. The library will be
     * available for media scanning after creation.
     *
     * @response LibraryResource
     * @status 201
     */
    #[Post('/', 'api.library.create')]
    public function create(CreateLibraryRequest $request): LibraryResource
    {
        $data = $request->validated();

        $library = Library::create($data);

        return new LibraryResource($library);
    }


    /**
     * Show library with comprehensive statistics
     *
     * Retrieves a single library by its slug identifier and includes both
     * formatted (human-readable) and raw statistical data about the library's content.
     *
     * @param string $slug The library's URL-friendly identifier
     *
     * @throws ModelNotFoundException When library is not found
     * @response LibraryResource
     */
    #[Get('/{slug}', 'api.library.show')]
    public function show(string $slug)
    {
        $library = Library::whereSlug($slug)->firstOrFail();

        return new LibraryResource($library);
    }

    /**
     * Update an existing library
     *
     * Updates library configuration using the provided slug identifier.
     * Only the fields included in the request will be updated.
     *
     * @param string $slug The library's URL-friendly identifier
     *
     * @throws ModelNotFoundException When library is not found
     * @response LibraryResource
     */
    #[Patch('/{slug}', 'api.library.update')]
    public function update(string $slug, UpdateLibraryRequest $request): LibraryResource
    {
        $library = Library::whereSlug($slug)->firstOrFail();

        $library->update($request->validated());

        return new LibraryResource($library);
    }


    /**
     * Delete a library
     *
     * Permanently removes a library and all associated data. This action cannot be undone.
     * Media files on disk are not affected, only the library record is removed.
     *
     * @param string $slug The library's URL-friendly identifier
     *
     * @throws ModelNotFoundException When library is not found
     * @status 204
     */
    #[Delete('/{slug}', 'api.library.delete')]
    public function destroy(string $slug): Response
    {
        Library::whereSlug($slug)->delete();

        return response(null, ResponseAlias::HTTP_NO_CONTENT);
    }

}
