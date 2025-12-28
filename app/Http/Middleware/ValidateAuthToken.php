<?php

namespace App\Http\Middleware;

use App\Modules\Auth\TokenBindingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ValidateAuthToken
{
    public function __construct(
        private readonly TokenBindingService $tokenBindingService,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            return $next($request);
        }

        $guard = Auth::guard('oauth');

        // Skip token validation if authenticated via passkey
        if ($guard->authMethod() === 'passkey') {
            return $next($request);
        }

        // Get the current OAuth token from the guard
        $token = $guard->token();

        if (!$token) {
            return $next($request);
        }

        // Check if token has metadata (first-party) or not (third-party OAuth)
        if ($token->hasDeviceBinding()) {
            // Validate security bindings for first-party tokens
            $validation = $this->tokenBindingService->validateTokenBinding($token, $request);

            if (!$validation['valid']) {
                $token->revoke();
                $guard->forgetUser();

                $message = match($validation['reason']) {
                    'fingerprint_mismatch' => 'Device fingerprint validation failed',
                    'session_mismatch' => 'Session validation failed',
                    'max_ip_changes_exceeded' => 'Too many location changes detected',
                    'concurrent_ip_usage' => 'Concurrent access detected - all sessions revoked',
                    'rapid_ip_changes' => 'Rapid IP changes detected',
                    'suspicious_geo_jump' => 'Suspicious location change detected',
                    default => 'Token validation failed',
                };

                return response()->json([
                    'message' => $message . '. Please re-authenticate.',
                    'code' => 'TOKEN_VALIDATION_FAILED',
                    'reason' => $validation['reason'],
                    'action' => $validation['action'] ?? 'reauth',
                ], 401);
            }
        }

        // Third-party OAuth tokens pass through without security checks
        return $next($request);
    }
}
