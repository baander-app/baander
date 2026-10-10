<?php

declare(strict_types=1);

namespace App\Library\Interface\Controller;

use App\Library\Application\Command\GrantLibraryAccessCommand;
use App\Library\Application\Command\RevokeLibraryAccessCommand;
use App\Library\Application\Query\ListLibraryAccessQuery;
use App\Library\Interface\Resource\LibraryAccessResource;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\DispatchesMessagesTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Which libraries a user may see, as administrators see and change it on the admin user page.
 * The `app:library:member:*` commands are the CLI counterparts; both dispatch the same
 * Application messages. Admins see every library regardless of these grants.
 */
#[OA\Tag(name: 'Admin / Users', description: 'User management for administrators')]
#[Route('/api/admin/users/{userId}/libraries', name: 'admin_user_libraries_', requirements: ['userId' => Requirement::UUID])]
#[IsGranted('ROLE_ADMIN')]
final class AdminUserLibraryAccessController
{
    use ApiResponsesTrait;
    use DispatchesMessagesTrait;

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[OA\Get(
        path: '/api/admin/users/{userId}/libraries',
        summary: "List every library with the user's access to it",
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: '200', description: 'Every library in display order', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: LibraryAccessResource::class))),
            ])),
            new OA\Response(response: '403', description: 'Forbidden; admins need the admin.can_view_users setting, as for the user list', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'User not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('USER_MANAGEMENT_LIST')]
    #[CliCounterpart('app:library:member:list')]
    public function index(string $userId): JsonResponse
    {
        return $this->successResponse(LibraryAccessResource::collection($this->dispatch(new ListLibraryAccessQuery($userId))));
    }

    #[OA\Put(
        path: '/api/admin/users/{userId}/libraries/{libraryId}',
        summary: 'Let the user see a library',
        description: 'Granting access the user already has changes nothing.',
        parameters: [
            new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'libraryId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'The library with the access granted', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: LibraryAccessResource::class)),
            ])),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'User or library not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{libraryId}', name: 'grant', requirements: ['libraryId' => Requirement::UUID], methods: ['PUT'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:library:member:grant')]
    public function grant(string $userId, string $libraryId): JsonResponse
    {
        return $this->successResponse(LibraryAccessResource::from($this->dispatch(new GrantLibraryAccessCommand($userId, $libraryId))));
    }

    #[OA\Delete(
        path: '/api/admin/users/{userId}/libraries/{libraryId}',
        summary: 'Stop the user seeing a library',
        description: "Revoking access the user lacks changes nothing. The user's next request is refused; signed media URLs already issued stay valid until they expire.",
        parameters: [
            new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'libraryId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'The library with the access revoked', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: LibraryAccessResource::class)),
            ])),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'User or library not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{libraryId}', name: 'revoke', requirements: ['libraryId' => Requirement::UUID], methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:library:member:revoke')]
    public function revoke(string $userId, string $libraryId): JsonResponse
    {
        return $this->successResponse(LibraryAccessResource::from($this->dispatch(new RevokeLibraryAccessCommand($userId, $libraryId))));
    }
}
