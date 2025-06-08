<?php

namespace App\Modules\Apm\Middleware;

use App\Modules\Apm\Services\SwooleMetricsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SwooleMetricsSampler
{
    // Remove constructor dependency injection to avoid holding service instance

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only collect metrics on a sample of requests to avoid overhead
        if ($this->shouldCollectMetrics($request)) {
            try {
                // Create fresh service instance for each collection
                $metricsService = app(SwooleMetricsService::class);
                $metricsService->collectOnce();
            } catch (\Throwable $e) {
                // Log error but don't fail the request
                logger()->debug('Failed to collect Swoole metrics in middleware', [
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $response;
    }

    /**
     * Determine if we should collect metrics for this request
     */
    private function shouldCollectMetrics(Request $request): bool
    {
        // Don't collect on health checks or static assets
        if ($this->isIgnoredRoute($request)) {
            return false;
        }

        // Collect metrics on a configurable percentage of requests
        $sampleRate = config('apm.swoole_metrics.sample_rate', 0.01); // 1% by default

        return mt_rand(1, 10000) <= ($sampleRate * 10000);
    }

    /**
     * Check if this is a route we should ignore
     */
    private function isIgnoredRoute(Request $request): bool
    {
        $ignoredPatterns = [
            '/health',
            '/ping',
            '/status',
            '/metrics',
            '/favicon.ico',
            '*.css',
            '*.js',
            '*.png',
            '*.jpg',
            '*.gif',
            '*.svg',
        ];

        $path = $request->path();

        foreach ($ignoredPatterns as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
