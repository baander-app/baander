<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\OAuth\Client;
use App\Models\User;
use App\Modules\OAuth\Entities\UserEntity;
use App\Services\AppConfigService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Prefix;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

/**
 * SPA OAuth Authentication Controller
 *
 * Handles OAuth 2.0 authentication specifically for Single Page Applications
 * using the Authorization Code flow with PKCE.
 *
 * @tags OAuth
 */
#[Prefix('oauth/spa')]
#[Group('Auth')]
class SpaAuthController extends Controller
{
    public function __construct(
        private readonly AuthorizationServer $authorizationServer,
        private readonly PsrHttpFactory      $psrFactory,
        private readonly AppConfigService    $appConfigService,
    )
    {
    }

    /**
     * Get SPA Client Configuration
     *
     * Returns OAuth client configuration needed for the SPA to initiate
     * the authorization code flow with PKCE.
     *
     * @unauthenticated
     * @response array{
     *   client_id: string,
     *   authorization_endpoint: string,
     *   token_endpoint: string,
     *   scopes: array<string>
     * }
     */
    #[Get('config', 'oauth.spa.config')]
    public function getConfig(): JsonResponse
    {
        $spaClient = Client::where('name', 'Bånder SPA Client')
            ->where('revoked', false)
            ->first();

        if (!$spaClient) {
            return response()->json([
                'error'   => 'spa_client_not_configured',
                'message' => 'SPA OAuth client not found. Please create it using: php artisan oauth:client:create --device --name "Bånder SPA Client"',
            ], 500);
        }

        return response()->json([
            'client_id'              => $spaClient->public_id,
            'authorization_endpoint' => config('app.url') . '/api/oauth/spa/authorize',
            'token_endpoint'         => config('app.url') . '/api/oauth/token',
            'scopes'                 => ['read', 'write', 'stream'],
        ]);
    }

    /**
     * SPA Authorization Endpoint
     *
     * Handles the authorization request for SPAs. If user is already logged in,
     * auto-approves first-party clients. Otherwise, requires login.
     *
     * @param Request $request Request with OAuth parameters and PKCE
     *
     * @unauthenticated
     * @response 302|array{
     *   authorization_code: string,
     *   state?: string
     * }
     */
    #[Get('authorize', 'oauth.spa.authorize')]
    public function authorizeRequest(Request $request)
    {
        $psrRequest = $this->psrFactory->createRequest($request);
        $psrResponse = $this->psrFactory->createResponse(new HttpFoundationResponse());

        try {
            $authRequest = $this->authorizationServer->validateAuthorizationRequest($psrRequest);

            $user = Auth::guard('web')->user();

            if (!$user) {
                return response()->json([
                    'error'     => 'login_required',
                    'message'   => 'User must be logged in to authorize',
                    'login_url' => '/login?' . http_build_query([
                            'redirect' => $request->fullUrl(),
                        ]),
                ], 401);
            }

            // Create user entity for OAuth
            $userEntity = new UserEntity();
            $userEntity->setIdentifier($user->id);

            $authRequest->setUser($userEntity);

            // Auto-approve only for first-party SPA clients
            $client = Client::wherePublicId($authRequest->getClient()->getIdentifier())->first();
            if ($client && $client->first_party) {
                $authRequest->setAuthorizationApproved(true);
            } else {
                // Third-party clients require explicit user consent
                // For now, we'll deny - this should be implemented with a consent screen
                return response()->json([
                    'error'   => 'consent_required',
                    'message' => 'Third-party client authorization requires explicit user consent',
                ], 403);
            }

            $response = $this->authorizationServer->completeAuthorizationRequest($authRequest, $psrResponse);

            // For SPAs, we want to return the authorization code instead of redirecting
            $location = $response->getHeader('Location')[0] ?? null;

            if ($location) {
                $parsed = parse_url($location);
                parse_str($parsed['fragment'] ?? $parsed['query'] ?? '', $params);

                return response()->json([
                    'authorization_code' => $params['code'] ?? null,
                    'state'              => $params['state'] ?? null,
                ]);
            }

            return response()->json(['error' => 'authorization_failed'], 400);

        } catch (OAuthServerException $exception) {
            return response()->json([
                'error'   => $exception->getErrorType(),
                'message' => $exception->getMessage(),
            ], $exception->getHttpStatusCode());
        } catch (\Exception $exception) {
            return response()->json(['error' => 'server_error'], 500);
        }
    }

    /**
     * Get Current User Info
     *
     * Returns current user information for OAuth-authenticated requests.
     *
     * @param Request $request Authenticated request
     *
     * @response array{
     *   id: string,
     *   name: string,
     *   email: string,
     *   email_verified_at: string|null,
     *   created_at: string,
     *   updated_at: string
     * }
     */
    #[Get('user', 'oauth.spa.user', ['oauth', 'oauth.scopes:read'])]
    public function getUser(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('oauth_user_id');
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['error' => 'user_not_found'], 404);
        }

        return response()->json([
            'id'                => $user->id,
            'name'              => $user->name,
            'email'             => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'created_at'        => $user->created_at,
            'updated_at'        => $user->updated_at,
        ]);
    }

    /**
     * Get App Configuration for Authenticated User
     *
     * Returns app configuration data for the authenticated SPA user.
     *
     * @param Request $request OAuth authenticated request
     *
     * @response array
     */
    #[Get('app-config', 'oauth.spa.app-config', ['oauth', 'oauth.scopes:read'])]
    public function getAppConfig(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('oauth_user_id');
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['error' => 'user_not_found'], 404);
        }

        // Set the user for the app config service
        Auth::setUser($user);

        return response()->json(
            $this->appConfigService->getAppConfig()->toArray(),
        );
    }
}
