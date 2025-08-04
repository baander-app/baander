<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Modules\OpenTelemetry\SpanBuilder;
use Symfony\Component\HttpFoundation\Response;

class OpenTelemetryRootSpan
{
    public function handle(Request $request, Closure $next)
    {
        $rootSpan = SpanBuilder::http('HTTP ' . $request->method())
            ->forHttpRequest($request)
            ->start();

        // 2️⃣ Activate it so every child span is automatically parented
        $scope = $rootSpan->activate();

        try {
            /** @var Response $response */
            $response = $next($request);

            // 3️⃣ Decorate the root span with response data
            $rootSpan->setAttribute('http.status_code', $response->getStatusCode());

            return $response;
        } finally {
            // 4️⃣ End root span (always runs, even on exceptions)
            $rootSpan->end();
            $scope->detach();
        }
    }
}