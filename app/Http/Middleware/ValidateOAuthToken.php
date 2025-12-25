<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Auth\OAuth\Psr7Factory;
use Closure;
use Illuminate\Http\Request;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Component\HttpFoundation\Response;

class ValidateOAuthToken
{
    public function __construct(
        private readonly ResourceServer $resourceServer,
        private readonly Psr7Factory $psr,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $psrRequest = $this->psr->createRequest($request);

        try {
            $psrRequest = $this->resourceServer->validateAuthenticatedRequest($psrRequest);

            // Add OAuth user ID to request for later use
            $request->attributes->set('oauth_user_id', $psrRequest->getAttribute('oauth_user_id'));
            $request->attributes->set('oauth_client_id', $psrRequest->getAttribute('oauth_client_id'));
            $request->attributes->set('oauth_scopes', $psrRequest->getAttribute('oauth_scopes', []));

        } catch (OAuthServerException $exception) {
            return response()->json([
                'error' => $exception->getErrorType(),
                'message' => $exception->getMessage(),
            ], $exception->getHttpStatusCode());
        }

        return $next($request);
    }
}
