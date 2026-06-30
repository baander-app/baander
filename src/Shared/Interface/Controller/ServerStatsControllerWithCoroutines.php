<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Swoole\CoroutineStatsProvider;
use App\Shared\Infrastructure\Swoole\SwoolePoolStatsProvider;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Redis;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Drop-in replacement for the existing ServerStatsController.
 *
 * Changes from the original:
 *   - Added CoroutineStatsProvider dependency injection
 *   - Added 'coroutine_details' key in the response payload
 *
 * To integrate: merge this into the existing ServerStatsController
 * or just add the new constructor arg and response key.
 */
#[OA\Tag(name: 'System', description: 'System utilities and background job monitoring')]
#[Route('/api/debug', name: 'debug_')]
final class ServerStatsControllerWithCoroutines
{
    private const float MEGABYTE = 1_048_576;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RedisClientFactory $redisClientFactory,
        private readonly SwoolePoolStatsProvider $poolStatsProvider,
        private readonly CoroutineStatsProvider $coroutineStatsProvider,  // <-- NEW
    )
    {
    }

    #[OA\Get(
        path: '/api/debug/stats',
        summary: 'Internal server diagnostics with coroutine and channel stats',
        responses: [
            new OA\Response(response: '200', description: 'Server stats snapshot',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', type: 'object')],
                    type: 'object',
                ),
            ),
        ],
    )]
    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $mb = self::MEGABYTE;

        $memory = [
            'usage'     => round(memory_get_usage() / $mb, 2),
            'peak'      => round(memory_get_peak_usage() / $mb, 2),
            'real'      => round(memory_get_usage(true) / $mb, 2),
            'real_peak' => round(memory_get_peak_usage(true) / $mb, 2),
        ];

        $process = [
            'pid'    => getmypid(),
            'uid'    => posix_getuid(),
            'gid'    => posix_getgid(),
            'user'   => posix_getpwuid(posix_getuid())['name'] ?? 'unknown',
            'uptime' => time() - (int)filemtime('/proc/1/cmdline'),
        ];

        $swoole = null;
        $server = swoole_get_vm_status();
        if (is_array($server) && count($server) > 0) {
            $swoole = $server;
        }

        $em = $this->entityManager;
        $unitOfWork = $em->getUnitOfWork();
        $doctrine = [
            'identity_map_size' => count($unitOfWork->getIdentityMap()),
            'scheduled_inserts' => count($unitOfWork->getScheduledEntityInsertions()),
            'scheduled_updates' => count($unitOfWork->getScheduledEntityUpdates()),
            'scheduled_deletes' => count($unitOfWork->getScheduledEntityDeletions()),
            'is_open'           => $em->isOpen(),
        ];

        $redis = null;
        try {
            $redis = $this->redisClientFactory->borrow(function (Redis $r) use ($mb): ?array {
                return [
                    'connected'         => true,
                    'ping'              => $r->ping() === 'PONG',
                    'db_size'           => $r->dbsize(),
                    'connected_clients' => (int)$r->info()['connected_clients'],
                    'used_memory'       => round(((int)$r->info()['used_memory']) / $mb, 2),
                    'maxmemory'         => round(((int)$r->info()['maxmemory']) / $mb, 2),
                ];
            });
        } catch (Throwable $e) {
            $redis = [
                'connected' => false,
                'error'     => $e->getMessage(),
            ];
        }

        $sseConnections = 0;
        try {
            $sseConnections = (int)$this->redisClientFactory->borrow(function (Redis $r): int {
                $total = 0;
                $iter = null;
                while (($keys = $r->scan($iter, 'sse:connections:*', 100)) !== false) {
                    foreach ($keys as $key) {
                        $total += (int)$r->get($key);
                    }
                    if ($iter === null || $iter === '0') {
                        break;
                    }
                }
                return $total;
            });
        } catch (Throwable) {
        }

        // NEW: coroutine and channel stats
        $coroutineDetails = $this->coroutineStatsProvider->getStats();

        return $this->successResponse([
            'memory'            => $memory,
            'process'           => $process,
            'swoole'            => $swoole,
            'coroutines'        => $swoole !== null ? [
                'coroutine_num'  => $swoole['coroutine_num'] ?? null,
                'connection_num' => $swoole['connection_num'] ?? null,
            ] : null,
            'coroutine_details' => $coroutineDetails,  // <-- NEW
            'pools'             => $this->poolStatsProvider->getStats(),
            'doctrine'          => $doctrine,
            'redis'             => $redis,
            'sse'               => [
                'active_connections' => $sseConnections,
            ],
        ]);
    }

    private function successResponse(array $data): JsonResponse
    {
        return new JsonResponse(['data' => $data], 200);
    }
}
