<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Controller;

use App\Metadata\Application\Command\SyncMetadataCommand;
use App\Metadata\Application\Port\MetadataAdminPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\DTO\ApiError;
use OpenApi\Spec as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'Admin', description: 'System administration endpoints')]
#[Route('/api/admin/metadata', name: 'admin_metadata_')]
final class MetadataAdminController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly MetadataAdminPortInterface $metadataAdmin,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[OA\Operation\Get(
        path: '/api/admin/metadata/sync-status',
        summary: 'Get metadata sync status',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Sync status',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'lastSyncAt' => new OA\Schema(type: 'string', nullable: true),
                                    'totalTracks' => new OA\Schema(type: 'integer'),
                                    'syncedTracks' => new OA\Schema(type: 'integer'),
                                    'pendingTracks' => new OA\Schema(type: 'integer'),
                                    'failedTracks' => new OA\Schema(type: 'integer'),
                                    'sources' => new OA\Schema(
                                        type: 'array',
                                        items: new OA\Schema(
                                            type: 'object',
                                            properties: [
                                                'name' => new OA\Schema(type: 'string'),
                                                'synced' => new OA\Schema(type: 'integer'),
                                                'failed' => new OA\Schema(type: 'integer'),
                                            ],
                                        ),
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/sync-status', name: 'sync_status', methods: ['GET'])]
    #[CliCounterpart('app:metadata:status')]
    public function syncStatus(): JsonResponse
    {
        return $this->successResponse($this->metadataAdmin->getSyncStatus());
    }

    #[OA\Operation\Post(
        path: '/api/admin/metadata/trigger-sync',
        summary: 'Trigger metadata sync',
        requestBody: new OA\RequestBody(
            content: new OA\MediaType\Json(
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'source' => new OA\Schema(
                            description: 'genres for the genre sync only; null or absent for one sync per library and one artist sync',
                            type: 'string',
                            nullable: true,
                            enum: ['genres'],
                        ),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Sync triggered',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'jobsDispatched' => new OA\Schema(
                                        description: 'Jobs queued: one per library and one for the artist sync, or one per album and song for the genre sync',
                                        type: 'integer',
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Unknown source',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/trigger-sync', name: 'trigger_sync', methods: ['POST'])]
    #[CliCounterpart('app:metadata:sync')]
    public function triggerSync(Request $request): JsonResponse
    {
        $content = $request->getContent();
        $body = $content !== '' ? (json_decode($content, true, 512, JSON_THROW_ON_ERROR) ?? []) : [];
        $source = is_array($body) ? ($body['source'] ?? null) : null;
        if ($source !== null && !is_string($source)) {
            throw new InvalidInputException('The sync source must be a string.', ['source' => $source]);
        }

        $jobsDispatched = $this->bus->dispatch(new SyncMetadataCommand($source))->last(HandledStamp::class)?->getResult();

        return $this->successResponse(['jobsDispatched' => $jobsDispatched]);
    }

    #[OA\Operation\Get(
        path: '/api/admin/metadata/providers',
        summary: 'List metadata providers with configuration status',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Provider list',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'data' => new OA\Schema(
                                type: 'array',
                                items: new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'name' => new OA\Schema(type: 'string'),
                                        'enabled' => new OA\Schema(type: 'boolean'),
                                        'configured' => new OA\Schema(type: 'boolean'),
                                    ],
                                ),
                            ),
                        ],
                    ),
                ),
            ),
            new OA\Response(
                response: '403',
                description: 'Forbidden',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/providers', name: 'providers', methods: ['GET'])]
    #[CliCounterpart('app:metadata:providers')]
    public function providers(): JsonResponse
    {
        return $this->successResponse($this->metadataAdmin->getProviders());
    }
}
