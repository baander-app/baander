<?php

declare(strict_types=1);

namespace App\Radio\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Radio\Application\Port\RadioSourcePortInterface;
use App\Radio\Interface\Request\CreateRadioSourceRequest;
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
                                            minLength: 21,
                                            maxLength: 21,
                                            pattern: '^[0-9a-zA-Z_-]{21}$',
                                        ),
                                        'name' => new OA\Schema(type: 'string'),
                                        'type' => new OA\Schema(type: 'string'),
                                        'syncUrl' => new OA\Schema(type: 'string', format: 'uri'),
                                        'syncSchedule' => new OA\Schema(type: 'string', nullable: true),
                                        'lastSync' => new OA\Schema(
                                            type: 'string',
                                            format: 'date-time',
                                            nullable: true,
                                        ),
                                    ],
                                ),
                            ),
                        ],
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

        return $this->successResponse($this->sourcePort->listSources());
    }

    #[OA\Operation\Post(
        path: '/api/radio/sources',
        summary: 'Create a radio source (admin)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    properties: [
                        'name' => new OA\Schema(type: 'string'),
                        'type' => new OA\Schema(type: 'string'),
                        'syncUrl' => new OA\Schema(type: 'string', format: 'uri'),
                        'syncConfig' => new OA\Schema(
                            type: 'array',
                            items: new OA\Schema(),
                        ),
                        'syncSchedule' => new OA\Schema(type: 'string', nullable: true),
                    ],
                    required: ['name', 'type', 'syncUrl'],
                ),
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
                                        minLength: 21,
                                        maxLength: 21,
                                        pattern: '^[0-9a-zA-Z_-]{21}$',
                                    ),
                                    'name' => new OA\Schema(type: 'string'),
                                    'type' => new OA\Schema(type: 'string'),
                                ],
                            ),
                        ],
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
