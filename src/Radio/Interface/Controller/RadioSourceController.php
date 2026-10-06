<?php

declare(strict_types=1);

namespace App\Radio\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Radio\Application\Port\RadioSourcePortInterface;
use App\Radio\Interface\Request\CreateRadioSourceRequest;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
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

    #[OA\Get(
        path: '/api/radio/sources',
        summary: 'List all radio sources',
        responses: [
            new OA\Response(response: '200', description: 'List of sources', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'type', type: 'string'),
                    new OA\Property(property: 'syncUrl', type: 'string', format: 'uri'),
                    new OA\Property(property: 'syncSchedule', type: 'string', nullable: true),
                    new OA\Property(property: 'lastSync', type: 'string', format: 'date-time', nullable: true),
                ], type: 'object'))],
                type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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

    #[OA\Post(
        path: '/api/radio/sources',
        summary: 'Create a radio source (admin)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'type', 'syncUrl'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'type', type: 'string'),
                    new OA\Property(property: 'syncUrl', type: 'string', format: 'uri'),
                    new OA\Property(property: 'syncConfig', type: 'array', items: new OA\Items()),
                    new OA\Property(property: 'syncSchedule', type: 'string', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '201', description: 'Source created', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'type', type: 'string'),
                ])],
                type: 'object',
            )),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Administrator role required', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
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
