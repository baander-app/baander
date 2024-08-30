<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TokenAbility;
use App\Support\JsonPaginator;
use App\Http\Requests\Library\{CreateLibraryRequest, LibraryIndexRequest, UpdateLibraryRequest};
use App\Http\Resources\Library\LibraryResource;
use App\Models\Library;
use Spatie\RouteAttributes\Attributes\{Delete, Get, Middleware, Patch, Post, Prefix};
use Illuminate\Http\Response;

#[Prefix('/libraries')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class LibraryController extends Controller
{
    /**
     * @param LibraryIndexRequest $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection<JsonPaginator<LibraryResource>>
     */
    #[Get('/', 'api.libraries.index')]
    public function index(LibraryIndexRequest $request)
    {
        $libraries = Library::paginate($request->query('perPage', 15));

        return LibraryResource::collection($libraries);
    }

    #[Post('/', 'api.library.create')]
    public function create(CreateLibraryRequest $request)
    {
        $data = $request->validated();

        $library = Library::create($data);

        return new LibraryResource($library);
    }

    #[Patch('/:slug', 'api.library.update')]
    public function update(string $slug, UpdateLibraryRequest $request)
    {
        $library = Library::whereSlug($slug)->firstOrFail();

        $library->update($request->validated());

        return new LibraryResource($library);
    }

    #[Delete('/:slug', 'api.library.delete')]
    public function destroy(string $slug)
    {
        Library::whereSlug($slug)->delete();

        return response(null, Response::HTTP_NO_CONTENT);
    }
}
