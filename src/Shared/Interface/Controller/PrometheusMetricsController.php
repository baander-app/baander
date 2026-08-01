<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Infrastructure\Swoole\SwoolePoolStatsProvider;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'System', description: 'System utilities and background job monitoring')]
final class PrometheusMetricsController
{
    public function __construct(
        private readonly SwoolePoolStatsProvider $poolStatsProvider,
    )
    {
    }

    #[OA\Get(
        path: '/metrics',
        description: 'Prometheus metrics endpoint. Returns Swoole and application metrics in Prometheus text exposition format.',
        summary: 'Prometheus metrics',
        responses: [
            new OA\Response(
                response: '200',
                description: 'Prometheus text format metrics',
            ),
        ],
    )]
    #[Route('/metrics', name: 'prometheus_metrics', methods: ['GET'])]
    public function __invoke(): Response
    {
        $lines = ['# HELP swoole_up Whether the Swoole worker process is running'];
        $lines[] = '# TYPE swoole_up gauge';
        $lines[] = 'swoole_up 1';

        $swooleStats = $this->getSwooleStats();
        if ($swooleStats !== []) {
            $this->appendSwooleMetrics($lines, $swooleStats);
        }

        $this->appendPoolMetrics($lines);

        $lines[] = '';

        return new Response(
            content: implode("\n", $lines),
            status: 200,
            headers: ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getSwooleStats(): array
    {
        if (!function_exists('swoole_get_vm_status')) {
            return [];
        }

        $stats = swoole_get_vm_status();

        return is_array($stats) ? $stats : [];
    }

    /**
     * @param string[] $lines
     * @param array<string, mixed> $stats
     */
    private function appendSwooleMetrics(array &$lines, array $stats): void
    {
        $metricMap = [
            'coroutine_num' => 'swoole_coroutine_num',
            'request_count' => 'swoole_request_count',
            'connection_num' => 'swoole_connection_num',
            'worker_num' => 'swoole_worker_num',
        ];

        foreach ($metricMap as $swooleKey => $metricName) {
            if (isset($stats[$swooleKey]) && is_numeric($stats[$swooleKey])) {
                $lines[] = sprintf('# HELP %s Current %s', $metricName, $swooleKey);
                $lines[] = sprintf('# TYPE %s gauge', $metricName);
                $lines[] = sprintf('%s %d', $metricName, (int) $stats[$swooleKey]);
            }
        }
    }

    /**
     * @param string[] $lines
     */
    private function appendPoolMetrics(array &$lines): void
    {
        $poolStats = $this->poolStatsProvider->getStats();

        $lines[] = '# HELP swoole_pool_active Number of active (assigned) connections in the Swoole service pool';
        $lines[] = '# TYPE swoole_pool_active gauge';
        $lines[] = '# HELP swoole_pool_free Number of free (available) connections in the Swoole service pool';
        $lines[] = '# TYPE swoole_pool_free gauge';
        $lines[] = '# HELP swoole_pool_limit Maximum connection limit for the Swoole service pool';
        $lines[] = '# TYPE swoole_pool_limit gauge';

        foreach ($poolStats as $i => $stat) {
            $lines[] = sprintf('swoole_pool_active{connection="%d"} %d', $i, $stat['active']);
            $lines[] = sprintf('swoole_pool_free{connection="%d"} %d', $i, $stat['free']);
            $lines[] = sprintf('swoole_pool_limit{connection="%d"} %d', $i, $stat['limit']);
        }
    }
}
