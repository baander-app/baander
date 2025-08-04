<?php

namespace App\Http\Controllers\Api\Libraries;

use App\Http\Controllers\Controller;
use App\Http\Requests\Library\{CreateLibraryRequest, LibraryIndexRequest, UpdateLibraryRequest};
use App\Http\Resources\Library\LibraryResource;
use App\Models\{Library, TokenAbility};
use App\Modules\Http\Pagination\JsonPaginator;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
     * Get a collection of media libraries
     *
     * @return AnonymousResourceCollection<JsonPaginator<LibraryResource>>
     */
    #[Get('/', 'api.libraries.index')]
    public function index(LibraryIndexRequest $request)
    {
        $libraries = (new \App\Models\Library)->paginate();

        return LibraryResource::collection($libraries);
    }

    /**
     * Create a library
     */
    #[Post('/', 'api.library.create')]
    public function create(CreateLibraryRequest $request)
    {
        $data = $request->validated();

        $library = (new \App\Models\Library)->create($data);

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
        $library = (new \App\Models\Library)->whereSlug($slug)->firstOrFail();

        return new LibraryResource($library);
    }

    /**
     * Update a library specified by the provided slug.
     */
    #[Patch('/{slug}', 'api.library.update')]
    public function update(string $slug, UpdateLibraryRequest $request)
    {
        $library = (new \App\Models\Library)->whereSlug($slug)->firstOrFail();

        $library->update($request->validated());

        return new LibraryResource($library);
    }

    /**
     * Delete a library
     */
    #[Delete('/{slug}', 'api.library.delete')]
    public function destroy(string $slug)
    {
        (new \App\Models\Library)->whereSlug($slug)->delete();

        return response(null, ResponseAlias::HTTP_NO_CONTENT);
    }
}
