<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Shared\Application\Exception\InvalidSettingValuesException;
use App\Shared\Application\Service\SettingDefinitionRegistry;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\UserPreference\Application\Command\ResetUserSettingCommand;
use App\UserPreference\Application\Command\SetUserSettingCommand;
use App\UserPreference\Application\Service\UserSettingsReader;
use App\UserPreference\Interface\Request\SetUserSettingRequest;
use App\UserPreference\Interface\Resource\UserSettingResource;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[OA\Tag(name: 'User Preferences', description: 'User preference management endpoints')]
#[Route('/api/user/settings', name: 'user_settings_')]
final class UserSettingsController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly UserSettingsReader $settings,
        private readonly SettingDefinitionRegistry $definitions,
        private readonly MessageBusInterface $commandBus,
        private readonly Security $security,
    ) {
    }

    #[OA\Get(
        path: '/api/user/settings',
        summary: "Get the signed-in user's settings with their effective values",
        responses: [
            new OA\Response(response: '200', description: 'User settings', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: UserSettingResource::class))),
            ])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->successResponse(UserSettingResource::collection($this->settings->entries($this->userId())));
    }

    #[OA\Put(
        path: '/api/user/settings/{key}',
        summary: "Set the signed-in user's choice for a setting",
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SetUserSettingRequest::class))),
        parameters: [new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: '200', description: 'The setting after the change', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: UserSettingResource::class)),
            ])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'The setting is not one the user may change', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Unknown setting', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Invalid value', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/{key}', name: 'set', requirements: ['key' => '[a-z0-9_.]+'], methods: ['PUT'])]
    public function set(string $key, #[MapRequestPayload] SetUserSettingRequest $request): JsonResponse
    {
        $userId = $this->userId();
        $denied = $this->denyUnlessEditable($key);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $this->commandBus->dispatch(new SetUserSettingCommand($userId->toString(), $key, $request->value));
        } catch (HandlerFailedException $e) {
            $cause = $e->getPrevious();
            if ($cause instanceof InvalidSettingValuesException) {
                return $this->errorResponse('Validation failed.', Response::HTTP_UNPROCESSABLE_ENTITY, $cause->messagesByKey());
            }

            throw $e;
        }

        return $this->successResponse(UserSettingResource::from($this->settings->entry($userId, $key)));
    }

    #[OA\Delete(
        path: '/api/user/settings/{key}',
        summary: "Remove the signed-in user's choice so the setting follows its default",
        parameters: [new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: '200', description: 'The setting after the reset', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: UserSettingResource::class)),
            ])),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'The setting is not one the user may change', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '404', description: 'Unknown setting', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/{key}', name: 'reset', requirements: ['key' => '[a-z0-9_.]+'], methods: ['DELETE'])]
    public function reset(string $key): JsonResponse
    {
        $userId = $this->userId();
        $denied = $this->denyUnlessEditable($key);
        if ($denied !== null) {
            return $denied;
        }

        $this->commandBus->dispatch(new ResetUserSettingCommand($userId->toString(), $key));

        return $this->successResponse(UserSettingResource::from($this->settings->entry($userId, $key)));
    }

    /**
     * A key no setting has is unknown; a system setting or one only an admin
     * may change is forbidden.
     */
    private function denyUnlessEditable(string $key): ?JsonResponse
    {
        $definition = $this->definitions->get($key);
        if ($definition === null) {
            return $this->notFound(sprintf('Unknown setting "%s".', $key));
        }

        return $definition->isUserEditable()
            ? null
            : $this->errorResponse('You cannot change this setting.', Response::HTTP_FORBIDDEN);
    }

    private function userId(): Uuid
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            throw new \LogicException('The firewall admitted a request without an authenticated user identity.');
        }

        return Uuid::fromString($user->getId());
    }
}
