<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckOAuthScopes
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $tokenScopes = $request->attributes->get('oauth_scopes', []);

        foreach ($scopes as $scope) {
            if (!in_array($scope, $tokenScopes, true)) {
                return response()->json([
                    'error' => 'insufficient_scope',
                    'message' => "The request requires scope: {$scope}",
                ], 403);
            }
        }

        return $next($request);
    }
}
