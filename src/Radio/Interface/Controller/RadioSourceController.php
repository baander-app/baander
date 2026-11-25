<?php

declare(strict_types=1);

namespace App\Radio\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Radio\Application\Port\RadioSourcePortInterface;
use App\Radio\Interface\Request\CreateRadioSourceRequest;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Spec as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Radio - Sources', description: 'Manage radio data sources')]
#[Route('/api/radio/sources', name: 'radio_source_')]
final class RadioSourceController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly Security $security,
        private readonly RadioSourcePortInterface $sourcePort,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/radio/sources',
        summary: 'List all radio sources',
        description: 'Only administrators receive syncUrl and syncConfig.',
        responses: [
            new OA\Response(
                response: '200',
                description: 'List of sources',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'publicId' => new OA\Schema(
                                            type: 'string',
                                            minLength: PublicId::LENGTH,
                                            maxLength: PublicId::LENGTH,
                                            pattern: PublicId::SCHEMA_PATTERN,
                                        ),
                                        'name' => new OA\Schema(type: 'string'),
                                        'type' => new OA\Schema(type: 'string'),
                                        'syncUrl' => new OA\Schema(description: 'Administrators only', type: 'string', format: 'uri'),
                                        'syncConfig' => new OA\Schema(description: 'Administrators only', type: 'object', additionalProperties: true),
                                        'syncSchedule' => new OA\Schema(type: 'string', nullable: true),
                                        'isActive' => new OA\Schema(type: 'boolean'),
                                        'createdAt' => new OA\Schema(type: 'string', format: 'date-time'),
                                        'updatedAt' => new OA\Schema(type: 'string', format: 'date-time'),
                                    ],
                                    required: ['publicId', 'name', 'type', 'syncSchedule', 'isActive', 'createdAt', 'updatedAt'],
                                ),
                            ),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        return $this->successResponse($this->sourcePort->listSources($this->security->isGranted('ROLE_ADMIN')));
    }

    #[OA\Operation\Post(
        path: '/api/radio/sources',
        summary: 'Create a radio source (admin)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: CreateRadioSourceRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '201',
                description: 'Source created',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'publicId' => new OA\Schema(
                                        type: 'string',
                                        minLength: PublicId::LENGTH,
                                        maxLength: PublicId::LENGTH,
                                        pattern: PublicId::SCHEMA_PATTERN,
                                    ),
                                    'name' => new OA\Schema(type: 'string'),
                                    'type' => new OA\Schema(type: 'string'),
                                    'syncUrl' => new OA\Schema(type: 'string', format: 'uri'),
                                    'syncConfig' => new OA\Schema(type: 'object', additionalProperties: true),
                                    'syncSchedule' => new OA\Schema(type: 'string', nullable: true),
                                    'isActive' => new OA\Schema(type: 'boolean'),
                                    'createdAt' => new OA\Schema(type: 'string', format: 'date-time'),
                                    'updatedAt' => new OA\Schema(type: 'string', format: 'date-time'),
                                ],
                                required: ['publicId', 'name', 'type', 'syncUrl', 'syncConfig', 'syncSchedule', 'isActive', 'createdAt', 'updatedAt'],
                            ),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Administrator role required',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Validation error',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ValidationError::class),
                ),
            ),
        ],
    )]
    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[CliCounterpart('app:radio:source:create')]
    public function create(#[MapRequestPayload] CreateRadioSourceRequest $payload): JsonResponse
    {
        $user = $this->getCurrentSecurityUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $result = $this->sourcePort->createSource(
            name: $payload->name,
            type: $payload->type,
            syncUrl: $payload->syncUrl,
            syncConfig: $payload->syncConfig,
            syncSchedule: $payload->syncSchedule,
        );

        return $this->successResponse($result, Response::HTTP_CREATED);
    }

    private function getCurrentSecurityUser(): ?AuthenticatedUserIdentityInterface
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return null;
        }

        return $user;
    }
}
