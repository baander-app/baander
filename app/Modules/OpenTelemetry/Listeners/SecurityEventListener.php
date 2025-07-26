<?php

namespace App\Modules\OpenTelemetry\Listeners;

use App\Modules\OpenTelemetry\SpanBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\StatusCode;

class SecurityEventListener
{
    public function handleSuspiciousActivity(Request $request, string $type, array $context = []): void
    {
        SpanBuilder::create('security.suspicious_activity')
            ->asInternal()
            ->attributes([
                'security.threat_type' => $type,
                'security.ip_address' => $request->ip(),
                'security.user_agent' => $request->userAgent(),
                'security.url' => $request->fullUrl(),
                'security.method' => $request->method(),
                'security.context' => json_encode($context),
            ])
            ->tags([
                'security.event' => 'suspicious_activity',
                'security.threat_type' => $type,
                'security.severity' => $this->determineSeverity($type),
            ])
            ->trace(function ($span) use ($request, $type, $context) {
                $span->setStatus(StatusCode::STATUS_ERROR, "Suspicious activity detected: {$type}");

                Log::channel('security')->warning('Suspicious activity detected', [
                    'type' => $type,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'url' => $request->fullUrl(),
                    'context' => $context,
                ]);
            });
    }

    public function handleRateLimitExceeded(Request $request, string $limiter, int $attempts): void
    {
        SpanBuilder::create('security.rate_limit_exceeded')
            ->asInternal()
            ->attributes([
                'security.rate_limiter' => $limiter,
                'security.attempts' => $attempts,
                'security.ip_address' => $request->ip(),
                'security.user_agent' => $request->userAgent(),
                'security.url' => $request->fullUrl(),
            ])
            ->tags([
                'security.event' => 'rate_limit_exceeded',
                'security.limiter' => $limiter,
                'security.severity' => 'medium',
            ])
            ->trace(function ($span) use ($request, $limiter, $attempts) {
                $span->setStatus(StatusCode::STATUS_ERROR, "Rate limit exceeded for {$limiter}");

                Log::channel('security')->warning('Rate limit exceeded', [
                    'limiter' => $limiter,
                    'attempts' => $attempts,
                    'ip' => $request->ip(),
                    'url' => $request->fullUrl(),
                ]);
            });
    }

    public function handleUnauthorizedAccess(Request $request, string $resource, ?string $userId = null): void
    {
        SpanBuilder::create('security.unauthorized_access')
            ->asInternal()
            ->attributes([
                'security.resource' => $resource,
                'security.user_id' => $userId,
                'security.ip_address' => $request->ip(),
                'security.user_agent' => $request->userAgent(),
                'security.url' => $request->fullUrl(),
                'security.method' => $request->method(),
            ])
            ->tags([
                'security.event' => 'unauthorized_access',
                'security.resource' => $resource,
                'security.severity' => 'high',
            ])
            ->trace(function ($span) use ($request, $resource, $userId) {
                $span->setStatus(StatusCode::STATUS_ERROR, "Unauthorized access to {$resource}");

                Log::channel('security')->error('Unauthorized access attempt', [
                    'resource' => $resource,
                    'user_id' => $userId,
                    'ip' => $request->ip(),
                    'url' => $request->fullUrl(),
                ]);
            });
    }

    private function determineSeverity(string $type): string
    {
        return match ($type) {
            'sql_injection', 'xss_attempt', 'path_traversal' => 'critical',
            'brute_force', 'csrf_token_mismatch' => 'high',
            'suspicious_user_agent', 'unusual_request_pattern' => 'medium',
            default => 'low',
        };
    }
}