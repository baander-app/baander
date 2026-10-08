<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller;

use App\Auth\Application\Command\User\ChangeEmailCommand;
use App\Auth\Application\Command\User\CreateUserCommand;
use App\Auth\Application\Command\User\DeleteUserCommand;
use App\Auth\Application\Command\User\DisableUserCommand;
use App\Auth\Application\Command\User\EnableUserCommand;
use App\Auth\Application\Command\User\RenameUserCommand;
use App\Auth\Application\Command\User\SetUserPasswordCommand;
use App\Auth\Application\Command\User\SetUserRolesCommand;
use App\Auth\Application\DTO\UserPage;
use App\Auth\Application\Port\UserPortInterface;
use App\Auth\Application\Query\User\ListUsersQuery;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Interface\Request\Admin\AdminAssignRolesRequest;
use App\Auth\Interface\Request\Admin\AdminCreateUserRequest;
use App\Auth\Interface\Request\Admin\AdminResetPasswordRequest;
use App\Auth\Interface\Request\Admin\AdminUpdateUserRequest;
use App\Auth\Interface\Resource\AdminUserResource;
use App\Shared\Domain\Model\Email;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Request\QueryParameters;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * User administration. Every action dispatches the Application message its `app:user:*`
 * command dispatches; the role checks and `admin.can_*` settings here apply to HTTP only.
 * A handler's not-found, conflict or invalid-input exception reaches ExceptionSubscriber,
 * which answers 404, 409 or 422.
 */
#[OA\Tag(name: 'Admin / Users', description: 'User management for administrators')]
#[Route('/api/admin/users', name: 'admin_users_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminUserController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly UserPortInterface $userService,
        private readonly UserLookup $userLookup,
        private readonly MessageBusInterface $commandBus,
        private readonly Security $security,
    ) {
    }

    #[OA\Get(
        path: '/api/admin/users',
        summary: 'List all users (paginated)',
        parameters: [
            new OA\Parameter(name: 'role', description: 'Filter by role', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'])),
            new OA\Parameter(name: 'disabled', description: 'Filter by disabled status', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'limit', description: 'Results per page', in: 'query', schema: new OA\Schema(type: 'integer', default: 50, maximum: 100, minimum: 1)),
            new OA\Parameter(name: 'offset', description: 'Result offset', in: 'query', schema: new OA\Schema(type: 'integer', default: 0, minimum: 0)),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Paginated user list',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: AdminUserResource::class))),
                        new OA\Property(property: 'meta', properties: [
                            new OA\Property(property: 'total', type: 'integer'),
                            new OA\Property(property: 'limit', type: 'integer'),
                            new OA\Property(property: 'offset', type: 'integer'),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '400', description: 'Invalid query parameters', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden; admins need the admin.can_view_users setting', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    #[IsGranted('USER_MANAGEMENT_LIST')]
    #[CliCounterpart('app:user:list')]
    public function list(Request $request): JsonResponse
    {
        $role = QueryParameters::optionalChoice($request->query, 'role', ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $disabled = QueryParameters::optionalBoolean($request->query, 'disabled');
        $pagination = QueryParameters::pagination($request->query, ListUsersQuery::DEFAULT_LIMIT, ListUsersQuery::MAX_LIMIT);

        $page = $this->dispatch(new ListUsersQuery($role, $disabled, $pagination->limit, $pagination->offset));
        assert($page instanceof UserPage);

        return new JsonResponse([
            'data' => AdminUserResource::collection($page->users),
            'meta' => [
                'total' => $page->total,
                'limit' => $pagination->limit,
                'offset' => $pagination->offset,
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/admin/users',
        summary: 'Create a new user',
        description: 'Super admins may create any user. Admins may create users with ROLE_USER only, and only while the admin.can_create_users setting is on.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: AdminCreateUserRequest::class)),
        ),
        responses: [
            new OA\Response(response: '201', description: 'User created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: AdminUserResource::class)),
            ])),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('', name: 'create', methods: ['POST'])]
    #[CliCounterpart('app:user:create')]
    public function create(
        #[MapRequestPayload] AdminCreateUserRequest $request,
    ): JsonResponse {
        // Checked here rather than with #[IsGranted]: the vote needs the requested roles,
        // and the payload is mapped only after #[IsGranted] subjects are resolved.
        if (!$this->security->isGranted('USER_MANAGEMENT_CREATE', $request->roles)) {
            return $this->forbidden('Admins may create users with ROLE_USER only, and only while admin.can_create_users is on.');
        }

        $email = new Email($request->email);

        if ($this->userService->existsWithEmail($email)) {
            return $this->errorResponse('This email address is already in use.', Response::HTTP_CONFLICT);
        }

        // The same use case as app:user:create, so both seed default preferences and announce the user.
        $user = $this->dispatch(new CreateUserCommand(
            email: $email,
            name: $request->name,
            plainPassword: $request->password,
            roles: $request->roles,
        ));

        return $this->successResponse(AdminUserResource::from($user), Response::HTTP_CREATED);
    }

    #[OA\Patch(
        path: '/api/admin/users/{id}',
        summary: 'Update a user',
        description: 'A new email address starts unverified and is sent a verification link. The CLI counterparts are app:user:rename and app:user:change-email.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: AdminUpdateUserRequest::class)),
        ),
        responses: [
            new OA\Response(response: '200', description: 'User updated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: AdminUserResource::class)),
            ])),
            new OA\Response(response: '404', description: 'User not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '409', description: 'Email already taken', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:user:rename')]
    public function update(string $id, #[MapRequestPayload] AdminUpdateUserRequest $request): JsonResponse
    {
        $user = null;

        // The same use case as the user's own change and `app:user:change-email`: the new
        // address starts unverified and is sent a verification link.
        if ($request->email !== null) {
            $user = $this->dispatch(new ChangeEmailCommand($id, $request->email));
        }

        // The same use case as `app:user:rename`.
        if ($request->name !== null) {
            $user = $this->dispatch(new RenameUserCommand($id, $request->name));
        }

        return $this->successResponse(AdminUserResource::from($user ?? $this->userLookup->byIdentifier($id)));
    }

    #[OA\Delete(
        path: '/api/admin/users/{id}',
        summary: 'Delete a user',
        responses: [
            new OA\Response(response: '204', description: 'User deleted'),
            new OA\Response(response: '404', description: 'User not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:user:delete')]
    public function delete(string $id): JsonResponse
    {
        $this->dispatch(new DeleteUserCommand($id));

        return $this->noContent();
    }

    #[OA\Post(
        path: '/api/admin/users/{id}/roles',
        summary: 'Assign roles to a user',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: AdminAssignRolesRequest::class)),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Roles assigned', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: AdminUserResource::class)),
            ])),
            new OA\Response(response: '404', description: 'User not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{id}/roles', name: 'assign_roles', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:user:roles')]
    public function assignRoles(string $id, #[MapRequestPayload] AdminAssignRolesRequest $request): JsonResponse
    {
        $user = $this->dispatch(new SetUserRolesCommand($id, array_values($request->roles)));

        return $this->successResponse(AdminUserResource::from($user));
    }

    #[OA\Post(
        path: '/api/admin/users/{id}/reset-password',
        summary: 'Reset a user password',
        description: 'Sets the password and signs the user out of every session.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: AdminResetPasswordRequest::class)),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Password reset', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'message', type: 'string'),
                ], type: 'object'),
            ])),
            new OA\Response(response: '404', description: 'User not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}/reset-password', name: 'reset_password', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:user:reset-password')]
    public function resetPassword(string $id, #[MapRequestPayload] AdminResetPasswordRequest $request): JsonResponse
    {
        // The same use case as `app:user:reset-password`: it also signs the user out everywhere.
        $this->dispatch(new SetUserPasswordCommand($id, $request->password));

        return $this->successResponse(['message' => 'Password reset successfully.']);
    }

    #[OA\Post(
        path: '/api/admin/users/{id}/disable',
        summary: 'Disable a user',
        responses: [
            new OA\Response(response: '200', description: 'User disabled', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: AdminUserResource::class)),
            ])),
            new OA\Response(response: '404', description: 'User not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}/disable', name: 'disable', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:user:disable')]
    public function disable(string $id): JsonResponse
    {
        // The same use case as `app:user:disable`: it also ends the user's sessions, and
        // disabling a disabled user succeeds without change.
        $user = $this->dispatch(new DisableUserCommand($id));

        return $this->successResponse(AdminUserResource::from($user));
    }

    #[OA\Post(
        path: '/api/admin/users/{id}/enable',
        summary: 'Enable a user',
        responses: [
            new OA\Response(response: '200', description: 'User enabled', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: AdminUserResource::class)),
            ])),
            new OA\Response(response: '404', description: 'User not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}/enable', name: 'enable', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:user:enable')]
    public function enable(string $id): JsonResponse
    {
        $user = $this->dispatch(new EnableUserCommand($id));

        return $this->successResponse(AdminUserResource::from($user));
    }

    /** Dispatches synchronously and returns the handler's result. */
    private function dispatch(object $message): mixed
    {
        return $this->commandBus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }
}
