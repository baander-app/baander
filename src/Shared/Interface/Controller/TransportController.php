<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\FailedMessageRetryException;
use App\Shared\Application\FailureTransportUnavailableException;
use App\Shared\Application\Port\AsyncTransportUnavailableException;
use App\Shared\Application\Port\FailedMessageAdministrationInterface;
use App\Shared\Application\Port\TransportStatusInterface;
use App\Shared\Interface\Attribute\CliCounterpart;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\ValidationError;
use App\Shared\Interface\Resource\FailedMessageResource;
use App\Shared\Interface\Resource\TransportStatusResource;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'System', description: 'System utilities and background job monitoring')]
#[Route('/api/monitor/transport', name: 'monitor_transport_')]
final class TransportController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly TransportStatusInterface $transportStatus,
        private readonly FailedMessageAdministrationInterface $failedMessages,
    ) {
    }

    private const string ID_REQUIREMENT = '[1-9][0-9]{0,17}';

    /**
     * Get transport status information.
     *
     * Returns queue depth for async and failed transports, consumer name,
     * and whether the consumer is currently running (best-effort).
     *
     * CLI counterpart: app:monitor:transport.
     */
    #[OA\Get(
        path: '/api/monitor/transport/status',
        description: 'Returns queue depths, consumer name, and consumer running status for the messenger transports. CLI counterpart: app:monitor:transport.',
        summary: 'Get transport status',
        responses: [
            new OA\Response(response: '200', description: 'Transport status',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'asyncQueueDepth', description: 'Number of pending messages in the async stream', type: 'integer'),
                        new OA\Property(property: 'failedQueueDepth', description: 'Number of messages held by the failure transport', type: 'integer'),
                        new OA\Property(property: 'consumerName', description: 'Configured consumer identifier', type: 'string'),
                        new OA\Property(property: 'consumerRunning', description: 'Whether a consumer is actively processing messages (best-effort)', type: 'boolean'),
                    ], type: 'object')],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '503', description: 'Redis or the failure transport is unavailable',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string')],
                    type: 'object',
                ),
            ),
        ],
    )]
    #[Route('/status', name: 'status', methods: ['GET'])]
    #[CliCounterpart('app:monitor:transport')]
    public function status(): JsonResponse
    {
        try {
            $status = $this->transportStatus->status();
        } catch (AsyncTransportUnavailableException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (FailureTransportUnavailableException $e) {
            return $this->transportUnavailable($e);
        }

        return $this->successResponse(TransportStatusResource::from($status));
    }

    /**
     * List the messages held by the failure transport, newest first.
     *
     * CLI counterpart: app:failed-message:list.
     */
    #[OA\Get(
        path: '/api/monitor/transport/failed',
        description: 'Lists the messages held by the failure transport, newest first, including messages waiting out a retry delay. CLI counterpart: app:failed-message:list.',
        summary: 'List failed messages',
        parameters: [
            new OA\Parameter(name: 'page', description: 'Page number', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
            new OA\Parameter(name: 'limit', description: 'Messages per page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, maximum: 100, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Paginated failed messages', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: new Model(type: FailedMessageResource::class))),
                new OA\Property(property: 'meta', required: ['current_page', 'last_page', 'per_page', 'total'], properties: [
                    new OA\Property(property: 'current_page', type: 'integer'),
                    new OA\Property(property: 'last_page', type: 'integer'),
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'total', type: 'integer'),
                ], type: 'object'),
            ])),
            new OA\Response(response: '503', description: 'Failure transport unavailable', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[Route('/failed', name: 'failed_list', methods: ['GET'])]
    #[CliCounterpart('app:failed-message:list')]
    public function listFailed(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));

        try {
            $result = $this->failedMessages->page($page, $limit);
        } catch (FailureTransportUnavailableException $e) {
            return $this->transportUnavailable($e);
        }

        return $this->paginatedResponse(FailedMessageResource::paginate(
            $result->messages,
            $page,
            max(1, (int) ceil($result->total / $limit)),
            $limit,
            $result->total,
        ));
    }

    /**
     * Show one failed message.
     *
     * CLI counterpart: messenger:failed:show {id}.
     */
    #[OA\Get(
        path: '/api/monitor/transport/failed/{id}',
        description: 'Returns one message held by the failure transport. CLI counterpart: messenger:failed:show {id}.',
        summary: 'Show a failed message',
        parameters: [
            new OA\Parameter(name: 'id', description: 'Failed message ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', pattern: '^[1-9][0-9]{0,17}$')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Failed message', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: FailedMessageResource::class)),
            ])),
            new OA\Response(response: '404', description: 'Message not found', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '503', description: 'Failure transport unavailable', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[Route('/failed/{id}', name: 'failed_show', requirements: ['id' => self::ID_REQUIREMENT], methods: ['GET'])]
    #[CliCounterpart('messenger:failed:show')]
    public function showFailed(string $id): JsonResponse
    {
        try {
            $message = $this->failedMessages->find($id);
        } catch (FailureTransportUnavailableException $e) {
            return $this->transportUnavailable($e);
        }

        if ($message === null) {
            return $this->notFound('Failed message not found.');
        }

        return $this->successResponse(FailedMessageResource::from($message));
    }

    /**
     * Flush all messages from the failed transport.
     *
     * Requires ?confirm=true query parameter to prevent accidental invocation.
     * CLI counterpart: app:failed-message:flush.
     */
    #[OA\Post(
        path: '/api/monitor/transport/failed/flush',
        description: 'Removes all messages from the failed transport, including messages waiting out a retry delay. Requires ?confirm=true query parameter. CLI counterpart: app:failed-message:flush.',
        summary: 'Flush all failed messages',
        parameters: [
            new OA\Parameter(name: 'confirm', description: 'Must be set to "true" to confirm the operation', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['true'])),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Failed messages flushed',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'flushed', description: 'Number of messages removed from the failed queue', type: 'integer'),
                    ], type: 'object')],
                ),
            ),
            new OA\Response(response: '422', description: 'Missing confirm parameter', content: new OA\JsonContent(ref: new Model(type: ValidationError::class))),
            new OA\Response(response: '503', description: 'Failure transport unavailable', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[Route('/failed/flush', name: 'failed_flush', methods: ['POST'])]
    #[CliCounterpart('app:failed-message:flush')]
    public function flushFailed(Request $request): JsonResponse
    {
        if ($request->query->get('confirm') !== 'true') {
            return $this->errorResponse(
                'Missing confirm parameter. Pass ?confirm=true to confirm the operation.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $flushed = $this->failedMessages->removeAll();
        } catch (FailureTransportUnavailableException $e) {
            return $this->transportUnavailable($e);
        }

        return $this->successResponse([
            'flushed' => $flushed,
        ]);
    }

    /**
     * Retry a specific failed message by its ID.
     *
     * CLI counterpart: messenger:failed:retry {id} --force.
     */
    #[OA\Post(
        path: '/api/monitor/transport/failed/{id}/retry',
        description: 'Handles a failed message again by running messenger:failed:retry {id} --force. A message that fails again returns to the failure transport under a new ID with its retry count increased, and stays listed until it succeeds or is removed.',
        summary: 'Retry a failed message',
        parameters: [
            new OA\Parameter(name: 'id', description: 'Failed message ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', pattern: '^[1-9][0-9]{0,17}$')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Message retried',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'retried', description: 'The ID of the retried message', type: 'string'),
                    ], type: 'object')],
                ),
            ),
            new OA\Response(response: '404', description: 'Message not found', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '500', description: 'Retry failed', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '503', description: 'Failure transport unavailable', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[Route('/failed/{id}/retry', name: 'failed_retry', requirements: ['id' => self::ID_REQUIREMENT], methods: ['POST'])]
    #[CliCounterpart('messenger:failed:retry')]
    public function retryFailed(string $id): JsonResponse
    {
        try {
            $found = $this->failedMessages->retry($id);
        } catch (FailureTransportUnavailableException $e) {
            return $this->transportUnavailable($e);
        } catch (FailedMessageRetryException $e) {
            return $this->errorResponse(
                sprintf('Failed to retry message: %s', $e->getMessage()),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        if (!$found) {
            return $this->notFound('Failed message not found.');
        }

        return $this->successResponse([
            'retried' => $id,
        ]);
    }

    /**
     * Remove a specific failed message by its ID.
     *
     * CLI counterpart: messenger:failed:remove {id} --force.
     */
    #[OA\Delete(
        path: '/api/monitor/transport/failed/{id}',
        description: 'Removes one message from the failure transport. CLI counterpart: messenger:failed:remove {id} --force.',
        summary: 'Remove a failed message',
        parameters: [
            new OA\Parameter(name: 'id', description: 'Failed message ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', pattern: '^[1-9][0-9]{0,17}$')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Message removed',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'removed', description: 'The ID of the removed message', type: 'string'),
                    ], type: 'object')],
                ),
            ),
            new OA\Response(response: '404', description: 'Message not found', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '503', description: 'Failure transport unavailable', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[Route('/failed/{id}', name: 'failed_remove', requirements: ['id' => self::ID_REQUIREMENT], methods: ['DELETE'])]
    #[CliCounterpart('messenger:failed:remove')]
    public function removeFailed(string $id): JsonResponse
    {
        try {
            $removed = $this->failedMessages->remove($id);
        } catch (FailureTransportUnavailableException $e) {
            return $this->transportUnavailable($e);
        }

        if (!$removed) {
            return $this->notFound('Failed message not found.');
        }

        return $this->successResponse([
            'removed' => $id,
        ]);
    }

    private function transportUnavailable(FailureTransportUnavailableException $e): JsonResponse
    {
        return $this->errorResponse(
            sprintf('Failure transport unavailable: %s', $e->getMessage()),
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
