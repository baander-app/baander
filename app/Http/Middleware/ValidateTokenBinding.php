<?php

namespace App\Http\Middleware;

use App\Modules\Auth\TokenBindingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class ValidateTokenBinding
{
    public function __construct(
        private readonly TokenBindingService $tokenBindingService
    ) {}

    public function handle(Request $request, Closure $next)
    {
        if (!Auth::guard('sanctum')->check()) {
            return $next($request);
        }

        $token = $request->user()->currentAccessToken();

        if (!$token instanceof PersonalAccessToken) {
            return $next($request);
        }

        // Skip validation for tokens without binding data (legacy tokens)
        if (!$token->client_fingerprint || !$token->session_id) {
            return $next($request);
        }

        $validation = $this->tokenBindingService->validateTokenBinding($token, $request);

        if (!$validation['valid']) {
            // Revoke the token
            $token->delete();

            $message = match($validation['reason']) {
                'fingerprint_mismatch' => 'Device fingerprint validation failed',
                'session_mismatch' => 'Session validation failed',
                'max_ip_changes_exceeded' => 'Too many location changes detected. Please re-authenticate for security.',
                default => 'Token binding validation failed'
            };

            return response()->json([
                'message' => $message . '. Please re-authenticate.',
                'code' => 'TOKEN_BINDING_FAILED',
                'reason' => $validation['reason'],
                'action' => $validation['action'] ?? 'reauth',
            ], 401);
        }

        return $next($request);
    }
}