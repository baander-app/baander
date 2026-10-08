<?php

declare(strict_types=1);

namespace App\Metadata\Interface\Controller;

use App\Metadata\Application\Command\SyncMetadataCommand;
use App\Metadata\Application\Port\MetadataAdminPortInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
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

    #[OA\Get(
        path: '/api/admin/metadata/sync-status',
        summary: 'Get metadata sync status',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Sync status',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'lastSyncAt', type: 'string', nullable: true),
                            new OA\Property(property: 'totalTracks', type: 'integer'),
                            new OA\Property(property: 'syncedTracks', type: 'integer'),
                            new OA\Property(property: 'pendingTracks', type: 'integer'),
                            new OA\Property(property: 'failedTracks', type: 'integer'),
                            new OA\Property(property: 'sources', type: 'array', items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'synced', type: 'integer'),
                                    new OA\Property(property: 'failed', type: 'integer'),
                                ],
                            )),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/sync-status', name: 'sync_status', methods: ['GET'])]
    #[CliCounterpart('app:metadata:status')]
    public function syncStatus(): JsonResponse
    {
        return $this->successResponse($this->metadataAdmin->getSyncStatus());
    }

    #[OA\Post(
        path: '/api/admin/metadata/trigger-sync',
        summary: 'Trigger metadata sync',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'source', description: 'genres for the genre sync only; null or absent for one sync per library', type: 'string', nullable: true, enum: ['genres']),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Sync triggered',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'jobsDispatched', description: 'Jobs queued: one per library, or one per album and song for the genre sync', type: 'integer'),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '422', description: 'Unknown source', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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

    #[OA\Get(
        path: '/api/admin/metadata/providers',
        summary: 'List metadata providers with configuration status',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Provider list',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'enabled', type: 'boolean'),
                                new OA\Property(property: 'configured', type: 'boolean'),
                            ],
                        )),
                    ],
                ),
            ),
            new OA\Response(response: '403', description: 'Forbidden', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/providers', name: 'providers', methods: ['GET'])]
    #[CliCounterpart('app:metadata:providers')]
    public function providers(): JsonResponse
    {
        return $this->successResponse($this->metadataAdmin->getProviders());
    }
}
