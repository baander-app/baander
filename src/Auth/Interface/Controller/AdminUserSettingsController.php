<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller;

use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Auth\Application\Service\AdminUserSettings;
use App\Auth\Interface\Request\Admin\SetAdminUserSettingRequest;
use App\Auth\Interface\Resource\AdminUserSettingResource;
use App\Shared\Application\Exception\InvalidSettingValuesException;
use App\Shared\Application\Exception\UnknownSettingException;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A user's settings as administrators see and change them. `app:user:setting`
 * is the CLI counterpart; both go through {@see AdminUserSettings}, which logs
 * every change.
 */
#[OA\Tag(name: 'Admin / Users', description: 'User management for administrators')]
#[Route('/api/admin/users/{id}/settings', name: 'admin_user_settings_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminUserSettingsController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly AdminUserSettings $settings,
        private readonly Security $security,
    ) {
    }

    #[OA\Get(
        path: '/api/admin/users/{id}/settings',
        summary: "List a user's settings",
        description: 'A stored value that is no longer allowed is returned with storedValueValid false.',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: '200', description: "The user's settings", content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: AdminUserSettingResource::class))),
            ])),
            new OA\Response(response: '403', description: 'Forbidden; admins need the admin.can_view_users setting, as for the user list', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'User not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('USER_MANAGEMENT_LIST')]
    #[CliCounterpart('app:user:setting')]
    public function index(string $id): JsonResponse
    {
        if (!self::isUuid($id)) {
            return $this->userNotFound();
        }

        try {
            $settings = $this->settings->settings($id);
        } catch (UserNotFoundException) {
            return $this->userNotFound();
        }

        return $this->successResponse(AdminUserSettingResource::collection($settings));
    }

    #[OA\Put(
        path: '/api/admin/users/{id}/settings/{key}',
        summary: "Set a user's choice for a setting",
        description: 'Works for every user setting, including ones users cannot change themselves. The change is logged.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SetAdminUserSettingRequest::class))),
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'The setting after the change', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: AdminUserSettingResource::class)),
            ])),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'User or setting not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid value', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{key}', name: 'set', requirements: ['key' => '[a-z0-9_.]+'], methods: ['PUT'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:user:setting')]
    public function set(string $id, string $key, #[MapRequestPayload] SetAdminUserSettingRequest $request): JsonResponse
    {
        if (!self::isUuid($id)) {
            return $this->userNotFound();
        }

        try {
            $setting = $this->settings->set($this->actorId(), $id, $key, $request->value);
        } catch (UserNotFoundException) {
            return $this->userNotFound();
        } catch (UnknownSettingException $e) {
            return $this->notFound($e->getMessage());
        } catch (InvalidSettingValuesException $e) {
            return $this->errorResponse('Validation failed.', Response::HTTP_UNPROCESSABLE_ENTITY, $e->messagesByKey());
        }

        return $this->successResponse(AdminUserSettingResource::from($setting));
    }

    #[OA\Delete(
        path: '/api/admin/users/{id}/settings/{key}',
        summary: "Remove a user's choice so the setting follows its default",
        description: 'The change is logged.',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'The setting after the reset', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: AdminUserSettingResource::class)),
            ])),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'User or setting not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{key}', name: 'reset', requirements: ['key' => '[a-z0-9_.]+'], methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[CliCounterpart('app:user:setting')]
    public function reset(string $id, string $key): JsonResponse
    {
        if (!self::isUuid($id)) {
            return $this->userNotFound();
        }

        try {
            $setting = $this->settings->reset($this->actorId(), $id, $key);
        } catch (UserNotFoundException) {
            return $this->userNotFound();
        } catch (UnknownSettingException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->successResponse(AdminUserSettingResource::from($setting));
    }

    /** Users are addressed by UUID here; the service would also accept an email address. */
    private static function isUuid(string $id): bool
    {
        try {
            Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private function userNotFound(): JsonResponse
    {
        return $this->notFound('User not found.');
    }

    private function actorId(): string
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            throw new \LogicException('The firewall admitted a request without an authenticated user identity.');
        }

        return $user->getId();
    }
}
