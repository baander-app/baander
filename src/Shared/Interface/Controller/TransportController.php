<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Infrastructure\Redis\RedisClientFactory;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Attribute\Model;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

#[IsGranted('ROLE_ADMIN')]
#[OA\Tag(name: 'System', description: 'System utilities and background job monitoring')]
#[Route('/api/monitor/transport', name: 'monitor_transport_')]
final class TransportController
{
    use ApiResponsesTrait;

    public function __construct(
        private readonly RedisClientFactory $redisClientFactory,
        private readonly string $consumerName,
    )
    {
    }

    /**
     * Get transport status information.
     *
     * Returns queue depth for async and failed transports, consumer name,
     * and whether the consumer is currently running (best-effort).
     */
    #[OA\Get(
        path: '/api/monitor/transport/status',
        description: 'Returns queue depths, consumer name, and consumer running status for the messenger transports.',
        summary: 'Get transport status',
        responses: [
            new OA\Response(response: '200', description: 'Transport status',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'asyncQueueDepth', description: 'Number of pending messages in the async stream', type: 'integer'),
                        new OA\Property(property: 'failedQueueDepth', description: 'Number of messages in the failed queue', type: 'integer'),
                        new OA\Property(property: 'consumerName', description: 'Configured consumer identifier', type: 'string'),
                        new OA\Property(property: 'consumerRunning', description: 'Whether a consumer is actively processing messages (best-effort)', type: 'boolean'),
                    ], type: 'object')],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '503', description: 'Redis unavailable',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string')],
                    type: 'object',
                ),
            ),
        ],
    )]
    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        try {
            $result = $this->redisClientFactory->borrow(function (\Redis $redis): array {
                // Async queue depth: XLEN on the Redis stream
                $asyncQueueDepth = (int)$redis->xlen('messages');

                // Failed queue depth: LLEN on the failed transport list
                $failedQueueDepth = (int)$redis->llen('messages_failed');

                // Consumer running: check XINFO CONSUMERS for our group/consumer
                $consumerRunning = false;
                try {
                    $consumers = $redis->xinfo('CONSUMERS', 'messages', 'baander');
                    foreach ($consumers as $consumer) {
                        if (($consumer['name'] ?? null) === $this->consumerName) {
                            $consumerRunning = true;
                            break;
                        }
                    }
                } catch (Throwable) {
                    // XINFO may fail if the consumer group has no active consumers
                    // or the stream/group doesn't exist yet
                }

                return [
                    'asyncQueueDepth'  => $asyncQueueDepth,
                    'failedQueueDepth' => $failedQueueDepth,
                    'consumerRunning'  => $consumerRunning,
                ];
            });

            return $this->successResponse([
                'asyncQueueDepth'  => $result['asyncQueueDepth'],
                'failedQueueDepth' => $result['failedQueueDepth'],
                'consumerName'     => $this->consumerName,
                'consumerRunning'  => $result['consumerRunning'],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse(
                sprintf('Redis unavailable: %s', $e->getMessage()),
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }
    }

    /**
     * Flush all messages from the failed transport.
     *
     * Requires ?confirm=true query parameter to prevent accidental invocation.
     */
    #[OA\Post(
        path: '/api/monitor/transport/failed/flush',
        description: 'Removes all messages from the failed transport. Requires ?confirm=true query parameter.',
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
                    type: 'object',
                ),
            ),
            new OA\Response(response: '422', description: 'Missing confirm parameter', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
            new OA\Response(response: '503', description: 'Redis unavailable', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/failed/flush', name: 'failed_flush', methods: ['POST'])]
    public function flushFailed(Request $request): JsonResponse
    {
        if ($request->query->get('confirm') !== 'true') {
            return $this->errorResponse(
                'Missing confirm parameter. Pass ?confirm=true to confirm the operation.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Use Symfony's messenger:failed:flush command to handle this properly
        // regardless of the underlying failed transport implementation.
        $process = new Process(['php', 'bin/console', 'messenger:failed:flush', '--no-interaction']);
        $process->run();

        if (!$process->isSuccessful()) {
            return $process->getErrorOutput()
                    |> trim(...)
                    |> (fn($x) => sprintf('Failed to flush failed messages: %s', $x))
                    |> (fn($x) => $this->errorResponse($x, Response::HTTP_INTERNAL_SERVER_ERROR));
        }

        // The command output contains the count of flushed messages
        $output = trim($process->getOutput());

        return $this->successResponse([
            'flushed' => $output,
        ]);
    }

    /**
     * Retry a specific failed message by its ID.
     *
     * Re-dispatches the message through the messenger worker.
     */
    #[OA\Post(
        path: '/api/monitor/transport/failed/{id}/retry',
        description: 'Re-dispatches a specific failed message through the messenger worker.',
        summary: 'Retry a failed message',
        parameters: [
            new OA\Parameter(name: 'id', description: 'Failed message ID', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'Message retried',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'retried', description: 'The ID of the retried message', type: 'string'),
                    ], type: 'object')],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '404', description: 'Message not found', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '500', description: 'Retry failed', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/failed/{id}/retry', name: 'failed_retry', methods: ['POST'])]
    public function retryFailed(string $id): JsonResponse
    {
        $process = new Process(['php', 'bin/console', 'messenger:failed:retry', $id]);
        $process->run();

        if (!$process->isSuccessful()) {
            return $process->getOutput()
                    |> trim(...)
                    |> (fn($x) => sprintf('Failed to retry message: %s', $x))
                    |> (fn($x) => $this->errorResponse($x, Response::HTTP_INTERNAL_SERVER_ERROR));
        }

        return $this->successResponse([
            'retried' => $id,
        ]);
    }
}
