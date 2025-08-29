<?php

namespace App\Http\Controllers\Api\Users;

use App\Http\Concerns\Filterable;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\{CreateUserRequest, UpdateUserRequest, UserIndexRequest};
use App\Http\Resources\User\UserResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use App\Models\{TokenAbility, User};
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\RouteAttributes\Attributes\{Delete, Get, Middleware, Patch, Post, Prefix};

/**
 * User management controller
 *
 * Handles user CRUD operations, profile management, and administrative functions.
 * Includes filtering, searching, and pagination capabilities for user listings.
 */
#[Prefix('users')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class UserController extends Controller
{
    use Filterable;

    /**
     * Get a paginated collection of users
     *
     * Returns a filtered and paginated list of all users in the system.
     * Supports global search across name and email fields, plus advanced filtering
     * options for administrative user management.
     *
     * @param UserIndexRequest $request Request with filtering and pagination parameters
     *
     * @response AnonymousResourceCollection<JsonPaginator<UserResource>>
     */
    #[Get('/', 'api.users.index')]
    public function index(UserIndexRequest $request): AnonymousResourceCollection
    {
        /** @var array<string> $columnsForGlobalFilter Searchable columns for global filter */
        $columnsForGlobalFilter = ['name', 'email'];

        // Apply filters and pagination using the Filterable trait
        $users = $this->applyFilters($request, User::class, $columnsForGlobalFilter);

        // Load common relations for better performance in list view
        $users->load(['roles', 'libraries']);

        // Paginated collection of user resources with applied filters.
        return UserResource::collection($users);
    }

    /**
     * Create a new user account
     *
     * Administrative endpoint that allows authorized users to create new user accounts
     * with specified roles and permissions. Created users will receive email verification.
     *
     * @param CreateUserRequest $request Request containing validated user data
     *
     * @throws AuthorizationException When user lacks admin privileges
     * @throws ValidationException When user data is invalid
     * @response UserResource
     * @status 201
     */
    #[Post('/', 'api.users.store')]
    public function create(CreateUserRequest $request): UserResource
    {
        // Verify admin permissions for user creation
        $this->authorize('create', User::class);

        /** @var User $user */
        $user = (new User)->create($request->validated());

        // Load relationships for comprehensive resource data
        $user->load(['roles', 'libraries']);

        // Send email verification if required
        if ($user instanceof MustVerifyEmail) {
            $user->sendEmailVerificationNotification();
        }

        // Newly created user resource.
        return new UserResource($user);
    }

    /**
     * Update an existing user
     *
     * Updates user information including profile data, roles, and permissions.
     * Users can update their own profiles, while administrators can update any user.
     *
     * @param User $user The user to update
     * @param UpdateUserRequest $request Request containing validated update data
     *
     * @throws AuthorizationException When user cannot update the target user
     * @throws ModelNotFoundException When user is not found
     * @response UserResource
     */
    #[Patch('/{user}', 'api.users.update')]
    public function update(User $user, UpdateUserRequest $request): UserResource
    {
        $this->authorize('update', $user);

        // Apply validated updates to the user
        $user->update($request->validated());

        // Load fresh data after update
        $user->refresh(['roles', 'libraries']);

        // Updated user resource.
        return new UserResource($user);
    }

    /**
     * Get authenticated user profile
     *
     * Returns the profile information of the currently authenticated user
     * including roles, permissions, and associated libraries.
     *
     * @param Request $request Authenticated request
     *
     * @response UserResource
     */
    #[Get('/me', 'api.users.me')]
    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        // Load comprehensive user data for profile view
        $user->loadMissing(['roles', 'libraries', 'preferences']);

        // Current user's profile resource.
        return new UserResource($user);
    }

    /**
     * Get a specific user's public profile
     *
     * Retrieves public profile information for a specific user.
     * Sensitive information is filtered based on privacy settings and permissions.
     *
     * @param User $user The user to retrieve
     *
     * @throws AuthorizationException When user cannot view the target user
     * @throws ModelNotFoundException When user is not found
     * @response UserResource
     */
    #[Get('/{user}', 'api.users.show')]
    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        // Load public-facing relationships
        $user->loadMissing(['roles']);

        // Public user profile resource.
        return new UserResource($user);
    }

    /**
     * Delete a user account
     *
     * Permanently removes a user account and all associated data. This action
     * cannot be undone. All user tokens are revoked and associated content is handled
     * according to the configured deletion policy.
     *
     * @param User $user The user to delete
     *
     * @throws AuthorizationException When user cannot delete the target user
     * @throws ModelNotFoundException When user is not found
     * @status 204
     */
    #[Delete('/{user}', 'api.users.destroy')]
    public function destroy(User $user): Response
    {
        $this->authorize('delete', $user);

        // Revoke all tokens for security
        $user->tokens()->delete();

        // Handle associated data according to deletion policy
        // (playlists, comments, etc. may be transferred or deleted)

        // Remove the user account
        $user->delete();

        // User successfully deleted - no content returned.
        return response(null, 204);
    }
}
