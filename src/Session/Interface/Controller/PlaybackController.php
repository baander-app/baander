<?php

declare(strict_types=1);

namespace App\Session\Interface\Controller;

use App\Session\Application\Port\PlaybackCoordinationInterface;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Interface\Request\AcknowledgePlaybackRequest;
use App\Session\Interface\Request\ClosePlaybackEndpointRequest;
use App\Session\Interface\Request\ControlPlaybackRequest;
use App\Session\Interface\Request\ObservePlaybackRequest;
use App\Session\Interface\Request\OverridePlaybackRequest;
use App\Session\Interface\Request\ReconcilePlaybackRequest;
use App\Session\Interface\Request\RecoverLocalPlaybackRequest;
use App\Session\Interface\Request\RegisterPlaybackEndpointRequest;
use App\Session\Interface\Request\TransferPlaybackRequest;
use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use OpenApi\Spec as OA;
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

    #[OA\Operation\Post(
        path: '/api/session/endpoints',
        summary: 'Register a fresh playback endpoint incarnation',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: RegisterPlaybackEndpointRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Complete playback snapshot',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(ref: PlaybackSnapshotResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid playback request',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
                response: '409',
                description: 'Playback authority or revision conflict',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/endpoints', name: 'endpoint', methods: ['POST'])]
    public function endpoint(#[MapRequestPayload] RegisterPlaybackEndpointRequest $input, Request $request): JsonResponse
    {
        return $this->respond(PlaybackOperation::Endpoint, $input->toArray(), $request);
    }

    #[OA\Operation\Post(
        path: '/api/session/transfer',
        summary: 'Begin an acknowledged playback transfer',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: TransferPlaybackRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Complete playback snapshot',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(ref: PlaybackSnapshotResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid playback request',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
                response: '409',
                description: 'Playback authority or revision conflict',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/transfer', name: 'transfer', methods: ['POST'])]
    public function transfer(#[MapRequestPayload] TransferPlaybackRequest $input, Request $request): JsonResponse
    {
        return $this->respond(PlaybackOperation::Transfer, $input->toArray(), $request);
    }

    #[OA\Operation\Post(
        path: '/api/session/ack',
        summary: 'Acknowledge an owned endpoint command',
        parameters: [
            new OA\Parameter(
                name: 'X-Baander-Playback-Endpoint',
                in: 'header',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: AcknowledgePlaybackRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Complete playback snapshot',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(ref: PlaybackSnapshotResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid playback request',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
                response: '409',
                description: 'Playback authority or revision conflict',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/ack', name: 'ack', methods: ['POST'])]
    public function acknowledge(#[MapRequestPayload] AcknowledgePlaybackRequest $input, Request $request): JsonResponse
    {
        return $this->respond(PlaybackOperation::Acknowledge, $input->toArray(), $request);
    }

    #[OA\Operation\Post(
        path: '/api/session/observe',
        summary: 'Renew endpoint presence and report observed playback',
        parameters: [
            new OA\Parameter(
                name: 'X-Baander-Playback-Endpoint',
                in: 'header',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: ObservePlaybackRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Complete playback snapshot',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(ref: PlaybackSnapshotResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid playback request',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
                response: '409',
                description: 'Playback authority or revision conflict',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/observe', name: 'observe', methods: ['POST'])]
    public function observe(#[MapRequestPayload] ObservePlaybackRequest $input, Request $request): JsonResponse
    {
        return $this->respond(PlaybackOperation::Observe, $input->toArray(), $request);
    }

    #[OA\Operation\Post(
        path: '/api/session/control',
        summary: 'Control the committed playback runner',
        parameters: [
            new OA\Parameter(
                name: 'X-Baander-Playback-Endpoint',
                in: 'header',
                description: 'Required when incarnationId is supplied. Authenticates the endpoint receiving its pending commands in the response.',
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: ControlPlaybackRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Complete playback snapshot',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(ref: PlaybackSnapshotResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid playback request',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
                response: '409',
                description: 'Playback authority or revision conflict',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/control', name: 'control', methods: ['POST'])]
    public function control(#[MapRequestPayload] ControlPlaybackRequest $input, Request $request): JsonResponse
    {
        return $this->respond(PlaybackOperation::Control, $input->toArray(), $request);
    }

    #[OA\Operation\Post(
        path: '/api/session/reconcile',
        summary: 'Stop an uncertain transfer target',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: ReconcilePlaybackRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Complete playback snapshot',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(ref: PlaybackSnapshotResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid playback request',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
                response: '409',
                description: 'Playback authority or revision conflict',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/reconcile', name: 'reconcile', methods: ['POST'])]
    public function reconcile(#[MapRequestPayload] ReconcilePlaybackRequest $input, Request $request): JsonResponse
    {
        return $this->respond(PlaybackOperation::Reconcile, $input->toArray(), $request);
    }

    #[OA\Operation\Post(
        path: '/api/session/recover-local',
        summary: 'Recover a proven stopped local browser incarnation',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: RecoverLocalPlaybackRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Capability-free snapshot and recorded recovery result',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(ref: PlaybackSnapshotResource::class),
                        ],
                    ),
                ),
            ),
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

    #[OA\Operation\Post(
        path: '/api/session/close',
        summary: 'Report that an endpoint is going away and its players have stopped',
        parameters: [
            new OA\Parameter(
                name: 'X-Baander-Playback-Endpoint',
                in: 'header',
                description: 'The capability of the incarnation that reports its close.',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: ClosePlaybackEndpointRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Complete playback snapshot without commands. The same for a repeated report and for an incarnation the session no longer names.',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(ref: PlaybackSnapshotResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid playback request',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
                response: '409',
                description: 'Endpoint capability missing or invalid',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/close', name: 'close', methods: ['POST'])]
    public function close(#[MapRequestPayload] ClosePlaybackEndpointRequest $input, Request $request): JsonResponse
    {
        return $this->respond(PlaybackOperation::Close, $input->toArray(), $request);
    }

    #[OA\Operation\Post(
        path: '/api/session/override',
        summary: 'End the hold of a destination that is no longer registered',
        parameters: [
            new OA\Parameter(
                name: 'X-Baander-Playback-Endpoint',
                in: 'header',
                description: 'The capability of the registered endpoint that asks.',
                required: true,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType\Json(
                schema: new OA\Schema(ref: OverridePlaybackRequest::class),
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Complete playback snapshot with the recorded result of the request. A repeated request returns the same result.',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(ref: PlaybackSnapshotResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid playback request',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
                response: '409',
                description: 'Playback authority or revision conflict, a destination that is still registered, or nothing to override',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
            ),
        ],
    )]
    #[Route('/override', name: 'override', methods: ['POST'])]
    public function override(#[MapRequestPayload] OverridePlaybackRequest $input, Request $request): JsonResponse
    {
        return $this->respond(PlaybackOperation::Override, $input->toArray(), $request);
    }

    #[OA\Operation\Get(
        path: '/api/session/snapshot',
        summary: 'Recover the complete playback snapshot',
        parameters: [
            new OA\Parameter(
                name: 'incarnationId',
                in: 'query',
                schema: new OA\Schema(type: 'string', format: 'uuid'),
            ),
            new OA\Parameter(
                name: 'X-Baander-Playback-Endpoint',
                in: 'header',
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Complete playback snapshot',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(
                        properties: [
                            'data' => new OA\Schema(ref: PlaybackSnapshotResource::class),
                        ],
                        required: ['data'],
                    ),
                ),
            ),
            new OA\Response(
                response: '422',
                description: 'Invalid playback request',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
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
                response: '409',
                description: 'Playback authority or revision conflict',
                content: new OA\MediaType\Json(
                    schema: new OA\Schema(ref: \App\Shared\Interface\DTO\ApiError::class),
                ),
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
