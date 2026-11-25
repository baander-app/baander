<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control\Operation;

use App\Shared\Infrastructure\Swoole\Control\ServerControlOperation;
use App\Shared\Infrastructure\LiveUpdates\LiveUpdateRuntime;
use App\Shared\Infrastructure\Swoole\SwoolePoolStatsProvider;
use Swoole\Coroutine;
use SwooleBundle\SwooleBundle\Server\HttpServer;

/**
 * One HTTP worker's process figures: memory, process identity, the requests it
 * has received, Swoole VM and coroutine counts, and the service pools. Runs in
 * every worker.
 *
 * The request count is `worker_request_count` of Server::stats(): the packets a
 * listener has handed this worker since it started, HTTP requests and control-socket
 * lines alike, each counted when its handler first yields or returns. Pipe messages,
 * which carry the fan-out, are not counted. A read over the control socket takes the
 * accepting worker's figure before its handler yields, so that figure leaves the read
 * out and the worker's next one includes it.
 *
 * It must not touch pooled (stateful) services such as the EntityManager: the
 * bundle releases pool slots only for coroutines it starts itself, and a control
 * request from the socket or another worker runs in one it did not start.
 */
final readonly class DebugStatsOperation implements ServerControlOperation
{
    public const string NAME = 'debug.stats';

    private const float MEGABYTE = 1_048_576;

    public function __construct(
        private SwoolePoolStatsProvider $poolStats,
        private LiveUpdateRuntime $liveUpdates,
        private HttpServer $httpServer,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function fansOut(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function handle(array $payload): array
    {
        $uid = posix_getuid();
        $user = posix_getpwuid($uid);
        $vm = swoole_get_vm_status();
        $coroutines = Coroutine::stats();

        return [
            'memory' => [
                'usage' => round(memory_get_usage() / self::MEGABYTE, 2),
                'peak' => round(memory_get_peak_usage() / self::MEGABYTE, 2),
                'real' => round(memory_get_usage(true) / self::MEGABYTE, 2),
                'real_peak' => round(memory_get_peak_usage(true) / self::MEGABYTE, 2),
            ],
            'process' => [
                'pid' => getmypid(),
                'uid' => $uid,
                'gid' => posix_getgid(),
                'user' => is_array($user) ? $user['name'] : 'unknown',
                'uptime' => time() - (int) filemtime('/proc/1/cmdline'),
            ],
            'requests' => [
                'received' => $this->httpServer->getServer()->stats()['worker_request_count'] ?? null,
            ],
            'swoole' => $vm !== [] ? $vm : null,
            'coroutines' => [
                'coroutine_num' => $coroutines['coroutine_num'] ?? null,
                'coroutine_peak_num' => $coroutines['coroutine_peak_num'] ?? null,
            ],
            'pools' => $this->poolStats->getStats(),
            'live_updates' => $this->liveUpdates->diagnostics(),
        ];
    }
}
