<?php

declare(strict_types=1);

namespace App\Session\Interface\Controller;

use App\Session\Application\Port\PlaybackCoordinationInterface;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Interface\Request\AcknowledgePlaybackRequest;
use App\Session\Interface\Request\ControlPlaybackRequest;
use App\Session\Interface\Request\ObservePlaybackRequest;
use App\Session\Interface\Request\ReconcilePlaybackRequest;
use App\Session\Interface\Request\RecoverLocalPlaybackRequest;
use App\Session\Interface\Request\RegisterPlaybackEndpointRequest;
use App\Session\Interface\Request\TransferPlaybackRequest;
use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use App\Session\Interface\Resource\PlaybackSnapshotResource;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Session', description: 'Listening session management')]
#[Route('/api/session', name: 'playback_')]
final readonly class PlaybackController
{
    use ApiResponsesTrait;

    public function __construct(private Security $security, private PlaybackCoordinationInterface $playback) {}

    #[OA\Post(
        path: '/api/session/endpoints',
        summary: 'Register a fresh playback endpoint incarnation',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: RegisterPlaybackEndpointRequest::class)),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Complete playback snapshot',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [new OA\Property(property: 'data', ref: new Model(type: PlaybackSnapshotResource::class))],
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid playback request',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
            new OA\Response(
                response: '409',
                description: 'Playback authority or revision conflict',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
        ],
    )]
    #[Route('/endpoints', name: 'endpoint', methods: ['POST'])]
    public function endpoint(#[MapRequestPayload] RegisterPlaybackEndpointRequest $input, Request $request): JsonResponse
    {
        return $this->respond(PlaybackOperation::Endpoint, $input->toArray(), $request);
    }

    #[OA\Post(
        path: '/api/session/transfer',
        summary: 'Begin an acknowledged playback transfer',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: TransferPlaybackRequest::class)),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Complete playback snapshot',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [new OA\Property(property: 'data', ref: new Model(type: PlaybackSnapshotResource::class))],
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid playback request',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
            new OA\Response(
                response: '409',
                description: 'Playback authority or revision conflict',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
        ],
    )]
    #[Route('/transfer', name: 'transfer', methods: ['POST'])]
    public function transfer(#[MapRequestPayload] TransferPlaybackRequest $input, Request $request): JsonResponse
    {
        return $this->respond(PlaybackOperation::Transfer, $input->toArray(), $request);
    }

    #[OA\Post(
        path: '/api/session/ack',
        summary: 'Acknowledge an owned endpoint command',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: AcknowledgePlaybackRequest::class)),
        ),
        parameters: [
            new OA\Parameter(name: 'X-Baander-Playback-Endpoint', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Complete playback snapshot',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [new OA\Property(property: 'data', ref: new Model(type: PlaybackSnapshotResource::class))],
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid playback request',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
            new OA\Response(
                response: '409',
                description: 'Playback authority or revision conflict',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
        ],
    )]
    #[Route('/ack', name: 'ack', methods: ['POST'])]
    public function acknowledge(#[MapRequestPayload] AcknowledgePlaybackRequest $input, Request $request): JsonResponse
    {
        return $this->respond(PlaybackOperation::Acknowledge, $input->toArray(), $request);
    }

    #[OA\Post(
        path: '/api/session/observe',
        summary: 'Renew endpoint presence and report observed playback',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: ObservePlaybackRequest::class)),
        ),
        parameters: [
            new OA\Parameter(name: 'X-Baander-Playback-Endpoint', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Complete playback snapshot',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [new OA\Property(property: 'data', ref: new Model(type: PlaybackSnapshotResource::class))],
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid playback request',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
            new OA\Response(
                response: '409',
                description: 'Playback authority or revision conflict',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
        ],
    )]
    #[Route('/observe', name: 'observe', methods: ['POST'])]
    public function observe(#[MapRequestPayload] ObservePlaybackRequest $input, Request $request): JsonResponse
    {
        return $this->respond(PlaybackOperation::Observe, $input->toArray(), $request);
    }

    #[OA\Post(
        path: '/api/session/control',
        summary: 'Control the committed playback runner',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: ControlPlaybackRequest::class)),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Complete playback snapshot',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [new OA\Property(property: 'data', ref: new Model(type: PlaybackSnapshotResource::class))],
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid playback request',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
            new OA\Response(
                response: '409',
                description: 'Playback authority or revision conflict',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
        ],
    )]
    #[Route('/control', name: 'control', methods: ['POST'])]
    public function control(#[MapRequestPayload] ControlPlaybackRequest $input, Request $request): JsonResponse
    {
        return $this->respond(PlaybackOperation::Control, $input->toArray(), $request);
    }

    #[OA\Post(
        path: '/api/session/reconcile',
        summary: 'Stop an uncertain transfer target',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: ReconcilePlaybackRequest::class)),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Complete playback snapshot',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [new OA\Property(property: 'data', ref: new Model(type: PlaybackSnapshotResource::class))],
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid playback request',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
            new OA\Response(
                response: '409',
                description: 'Playback authority or revision conflict',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
        ],
    )]
    #[Route('/reconcile', name: 'reconcile', methods: ['POST'])]
    public function reconcile(#[MapRequestPayload] ReconcilePlaybackRequest $input, Request $request): JsonResponse
    {
        return $this->respond(PlaybackOperation::Reconcile, $input->toArray(), $request);
    }

    #[OA\Post(
        path: '/api/session/recover-local',
        summary: 'Recover a proven stopped local browser incarnation',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RecoverLocalPlaybackRequest::class))),
        responses: [
            new OA\Response(response: '200', description: 'Capability-free snapshot and recorded recovery result', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: new Model(type: PlaybackSnapshotResource::class))],
            )),
            new OA\Response(response: '401', description: 'Not authenticated'),
            new OA\Response(response: '409', description: 'Recovery authority refused'),
            new OA\Response(response: '422', description: 'Invalid recovery request'),
        ],
    )]
    #[Route('/recover-local', name: 'recover_local', methods: ['POST'])]
    public function recoverLocal(#[MapRequestPayload] RecoverLocalPlaybackRequest $input, Request $request): JsonResponse
    {
        return $this->respond(PlaybackOperation::RecoverLocal, $input->toArray(), $request);
    }

    #[OA\Get(
        path: '/api/session/snapshot',
        summary: 'Recover the complete playback snapshot',
        parameters: [
            new OA\Parameter(name: 'incarnationId', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'X-Baander-Playback-Endpoint', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Complete playback snapshot',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [new OA\Property(property: 'data', ref: new Model(type: PlaybackSnapshotResource::class))],
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid playback request',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
            new OA\Response(
                response: '401',
                description: 'Not authenticated',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
            new OA\Response(
                response: '409',
                description: 'Playback authority or revision conflict',
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
        ],
    )]
    #[Route('/snapshot', name: 'snapshot', methods: ['GET'])]
    public function snapshot(Request $request): JsonResponse
    {
        $incarnation = $request->query->get('incarnationId');
        return $this->respond(PlaybackOperation::Snapshot, is_string($incarnation) ? ['incarnationId' => $incarnation] : [], $request);
    }

    /** @param array<string,mixed> $input */
    private function respond(PlaybackOperation $operation, array $input, Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }
        return $this->successResponse(PlaybackSnapshotResource::from($this->playback->execute(
            Uuid::fromString($user->getId()),
            $operation,
            $input,
            $request->headers->get('X-Baander-Playback-Endpoint'),
        )));
    }
}
