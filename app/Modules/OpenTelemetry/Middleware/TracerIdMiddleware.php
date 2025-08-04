<?php

namespace App\Modules\OpenTelemetry\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

class TracerIdMiddleware
{
    public const string HEADER_NAME = 'X-Tracer-Id';

    public function handle(Request $request, Closure $next)
    {
        if (!$request->hasHeader(self::HEADER_NAME)) {
            $request->headers->set(self::HEADER_NAME, Uuid::uuid4()->toString());
        }

        return $next($request);
    }
}