<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\Command\ResetSystemSettingCommand;
use App\Shared\Application\Command\UpdateSystemSettingsCommand;
use App\Shared\Application\Exception\InvalidSettingValuesException;
use App\Shared\Application\Exception\UnknownSettingException;
use App\Shared\Application\Service\SettingDefinitionRegistry;
use App\Shared\Application\Service\SystemSettings;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Request\UpdateSystemSettingsRequest;
use App\Shared\Interface\Resource\SettingDefinitionResource;
use App\Shared\Interface\Resource\SystemSettingResource;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The HTTP counterpart of the app:settings:* commands. */
#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'Admin', description: 'System administration endpoints')]
#[Route('/api/admin/settings', name: 'admin_settings_')]
final class SystemSettingsController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly SystemSettings $settings,
        private readonly SettingDefinitionRegistry $definitions,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/admin/settings',
        summary: 'Get every system setting with its effective and stored value',
        responses: [
            new OA\Response(
                response: '200',
                description: 'System settings',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(ref: SystemSettingResource::class),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('', name: 'index', methods: ['GET'])]
    #[CliCounterpart('app:settings:list')]
    public function index(): JsonResponse
    {
        return $this->successResponse(SystemSettingResource::collection($this->settings->entries()));
    }

    #[OA\Operation\Get(
        path: '/api/admin/settings/definitions',
        summary: 'Get the definition of every setting, server-wide and per-user',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Setting definitions',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(ref: SettingDefinitionResource::class),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/definitions', name: 'definitions', methods: ['GET'])]
    #[CliCounterpart('app:settings:definitions')]
    public function definitions(): JsonResponse
    {
        return $this->successResponse(SettingDefinitionResource::collection($this->definitions->all()));
    }

    #[OA\Operation\Patch(
        path: '/api/admin/settings',
        summary: 'Update system settings (SUPER_ADMIN only); any invalid value rejects the whole request',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'settings' => new OA\Schema(
                            description: 'Setting key to new value',
                            type: 'object',
                            additionalProperties: true,
                        ),
                    ],
                    required: ['settings'],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Updated settings',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(ref: SystemSettingResource::class),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '400',
                description: 'Malformed request body',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden — SUPER_ADMIN only',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Unknown setting or invalid value, per key; nothing was written',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('', name: 'update', methods: ['PATCH'])]
    #[IsGranted('SYSTEM_SETTINGS')]
    #[CliCounterpart('app:settings:set')]
    public function update(#[MapRequestPayload] UpdateSystemSettingsRequest $request): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new UpdateSystemSettingsCommand($request->settings));
        } catch (HandlerFailedException $e) {
            $cause = $e->getPrevious();
            if ($cause instanceof InvalidSettingValuesException) {
                return $this->errorResponse('Validation failed.', Response::HTTP_UNPROCESSABLE_ENTITY, $cause->messagesByKey());
            }

            throw $e;
        }

        return $this->successResponse(SystemSettingResource::collection($this->settings->entries()));
    }

    #[OA\Operation\Delete(
        path: '/api/admin/settings/{key}',
        summary: 'Reset a system setting to its default (SUPER_ADMIN only)',
        parameters: [
            new OA\Parameter(
                name: 'key',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'The setting after the reset',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(ref: SystemSettingResource::class),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden — SUPER_ADMIN only',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '404',
                description: 'Unknown setting',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/{key}', name: 'reset', requirements: ['key' => '[a-z0-9_.]+'], methods: ['DELETE'])]
    #[IsGranted('SYSTEM_SETTINGS')]
    #[CliCounterpart('app:settings:reset')]
    public function reset(string $key): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new ResetSystemSettingCommand($key));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof UnknownSettingException) {
                return $this->notFound($e->getPrevious()->getMessage());
            }

            throw $e;
        }

        return $this->successResponse(SystemSettingResource::from($this->settings->entry($key)));
    }
}
