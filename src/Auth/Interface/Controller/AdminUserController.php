<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller;

use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Port\UserPortInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Interface\Request\Admin\AdminAssignRolesRequest;
use App\Auth\Interface\Request\Admin\AdminCreateUserRequest;
use App\Auth\Interface\Request\Admin\AdminResetPasswordRequest;
use App\Auth\Interface\Request\Admin\AdminUpdateUserRequest;
use App\Auth\Interface\Resource\AdminUserResource;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Admin / Users', description: 'User management for administrators')]
#[Route('/api/admin/users', name: 'admin_users_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminUserController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly UserPortInterface $userService,
        private readonly Security $security,
    ) {
    }

    #[OA\Get(
        path: '/api/admin/users',
        summary: 'List all users (paginated)',
        parameters: [
            new OA\Parameter(name: 'role', description: 'Filter by role', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'])),
            new OA\Parameter(name: 'disabled', description: 'Filter by disabled status', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'limit', description: 'Results per page', in: 'query', schema: new OA\Schema(type: 'integer', default: 50)),
            new OA\Parameter(name: 'offset', description: 'Result offset', in: 'query', schema: new OA\Schema(type: 'integer', default: 0)),
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
        ],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $role = $request->query->get('role');
        $disabled = $request->query->has('disabled') ? filter_var($request->query->get('disabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        $limit = (int) $request->query->get('limit', 50);
        $offset = (int) $request->query->get('offset', 0);

        $users = $this->userService->findAll($role, $disabled, $limit, $offset);
        $total = $this->userService->count($role, $disabled);

        return new JsonResponse([
            'data' => AdminUserResource::collection($users),
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/admin/users',
        summary: 'Create a new user',
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
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function create(
        #[MapRequestPayload] AdminCreateUserRequest $request,
        PasswordHasherInterface $passwordHasher,
    ): JsonResponse {
        $email = new Email($request->email);

        if ($this->userService->existsWithEmail($email)) {
            return $this->errorResponse('This email address is already in use.', Response::HTTP_CONFLICT);
        }

        $user = User::createByOperator(
            email: $email,
            hashedPassword: $passwordHasher->hash($request->password),
            name: $request->name,
            roles: $request->roles,
        );

        $this->userService->save($user);

        return $this->successResponse(AdminUserResource::from($user), Response::HTTP_CREATED);
    }

    #[OA\Patch(
        path: '/api/admin/users/{id}',
        summary: 'Update a user',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: AdminUpdateUserRequest::class)),
        ),
        responses: [
            new OA\Response(response: '200', description: 'User updated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: AdminUserResource::class)),
            ])),
            new OA\Response(response: '404', description: 'User not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function update(string $id, #[MapRequestPayload] AdminUpdateUserRequest $request): JsonResponse
    {
        $user = $this->findUserOr404($id);
        if ($user === null) {
            return $this->notFound('User not found.');
        }

        if ($request->name !== null) {
            $user->updateName($request->name);
        }

        if ($request->email !== null) {
            $newEmail = new Email($request->email);
            if ($newEmail->toString() !== $user->getEmail() && $this->userService->existsWithEmail($newEmail)) {
                return $this->errorResponse('This email address is already in use.', Response::HTTP_CONFLICT);
            }
            $user->changeEmail($newEmail->toString());
        }

        $this->userService->save($user);

        return $this->successResponse(AdminUserResource::from($user));
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
    public function delete(string $id): JsonResponse
    {
        $user = $this->findUserOr404($id);
        if ($user === null) {
            return $this->notFound('User not found.');
        }

        $this->userService->delete($user->getId());

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
        ],
    )]
    #[Route('/{id}/roles', name: 'assign_roles', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function assignRoles(string $id, #[MapRequestPayload] AdminAssignRolesRequest $request): JsonResponse
    {
        $user = $this->findUserOr404($id);
        if ($user === null) {
            return $this->notFound('User not found.');
        }

        $user->getState()->roles = $request->roles;
        $user->getState()->updatedAt = new \DateTimeImmutable();

        $this->userService->save($user);

        return $this->successResponse(AdminUserResource::from($user));
    }

    #[OA\Post(
        path: '/api/admin/users/{id}/reset-password',
        summary: 'Reset a user password',
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
    public function resetPassword(string $id, #[MapRequestPayload] AdminResetPasswordRequest $request, PasswordHasherInterface $passwordHasher): JsonResponse
    {
        $user = $this->findUserOr404($id);
        if ($user === null) {
            return $this->notFound('User not found.');
        }

        $user->changePassword($passwordHasher->hash($request->password));

        $this->userService->save($user);

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
    public function disable(string $id): JsonResponse
    {
        $user = $this->findUserOr404($id);
        if ($user === null) {
            return $this->notFound('User not found.');
        }

        $user->disable();
        $this->userService->save($user);

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
    public function enable(string $id): JsonResponse
    {
        $user = $this->findUserOr404($id);
        if ($user === null) {
            return $this->notFound('User not found.');
        }

        $user->enable();
        $this->userService->save($user);

        return $this->successResponse(AdminUserResource::from($user));
    }

    private function findUserOr404(string $id): ?User
    {
        return $this->userService->findByUuid(Uuid::fromString($id));
    }
}
