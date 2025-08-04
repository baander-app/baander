<?php

namespace App\Http\Controllers\Api\Users;

use App\Http\Concerns\Filterable;
use App\Http\Requests\User\{CreateUserRequest, UpdateUserRequest, UserIndexRequest};
use App\Http\Resources\User\UserResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Models\{TokenAbility, User};
use App\Modules\Http\Pagination\JsonPaginator;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\{Delete, Get, Middleware, Patch, Post, Prefix};

#[Prefix('users')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class UserController
{
    use Filterable;

    /**
     * Get a collection of users
     *
     * @return AnonymousResourceCollection<JsonPaginator<UserResource>>
     */
    #[Get('/', 'api.users.index')]
    public function index(UserIndexRequest $request)
    {
        $columnsForGlobalFilter = ['name', 'email'];
        $users = $this->applyFilters($request, User::class, $columnsForGlobalFilter);

        return UserResource::collection($users);
    }

    /**
     * Create user
     *
     * This is endpoint allows administrators to create users
     */
    #[Post('/', 'api.users.store')]
    public function create(CreateUserRequest $request)
    {
        $user = (new \App\Models\User)->create($request->validated());

        return new UserResource($user);
    }

    /**
     * Update a user
     */
    #[Patch('/{user}', 'api.users.update')]
    public function update(User $user, UpdateUserRequest $request)
    {
        $user->update($request->validated());

        return new UserResource($user);
    }

    /**
     * Get the authenticated user
     */
    #[Get('/me', 'api.users.me')]
    public function me(Request $request)
    {
        return new UserResource($request->user());
    }


    /**
     * Get small user detail info
     */
    #[Get('/{user}', 'api.users.show')]
    public function show(User $user)
    {
        return new UserResource($user);
    }

    /**
     * Delete a user
     */
    #[Delete('/{user}', 'api.users.destroy')]
    public function destroy($user)
    {
        $user->delete();

        return response(null, 204);
    }
}