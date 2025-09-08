<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\OAuth\DeviceCode;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

/**
 * OAuth 2.0 Server Controller
 *
 * Handles OAuth 2.0 flows including authorization code, client credentials,
 * password grant, device code flow, and token management.
 *
 * @tags OAuth
 */
#[Prefix('oauth')]
class OAuthController extends Controller
{
    public function __construct(
        private readonly AuthorizationServer $authorizationServer,
        private readonly ResourceServer $resourceServer,
        private readonly PsrHttpFactory $psrFactory,
    ) {
    }

    /**
     * OAuth 2.0 Authorization Endpoint
     *
     * Handles the authorization code flow authorization endpoint.
     * Redirects users to consent screen and generates authorization codes.
     *
     * @param Request $request Request with client_id, redirect_uri, response_type, scope, and state
     *
     * @throws OAuthServerException When request is invalid
     * @unauthenticated
     * @response 302
     */
    #[Get('authorize', 'oauth.authorize')]
    public function authorizeCodeFlow(Request $request): Response
    {
        $psrRequest = $this->psrFactory->createRequest($request);
        $psrResponse = $this->psrFactory->createResponse(new HttpFoundationResponse());

        try {
            $authRequest = $this->authorizationServer->validateAuthorizationRequest($psrRequest);

            // In a real implementation, you'd show a consent screen here
            // For now, we'll auto-approve for first-party clients

            $user = $request->user();
            if ($user) {
                $authRequest->setUser(new \App\Modules\OAuth\Entities\UserEntity());
                $authRequest->getUser()->setIdentifier($user->id);
                $authRequest->setAuthorizationApproved(true);

                return $this->convertResponse(
                    $this->authorizationServer->completeAuthorizationRequest($authRequest, $psrResponse)
                );
            }

            // Redirect to login if not authenticated
            return redirect('/login?' . http_build_query([
                    'redirect' => $request->fullUrl()
                ]));

        } catch (OAuthServerException $exception) {
            return $this->convertResponse(
                $exception->generateHttpResponse($psrResponse)
            );
        } catch (Exception $exception) {
            return response()->json(['error' => 'server_error'], 500);
        }
    }

    /**
     * OAuth 2.0 Token Endpoint
     *
     * Handles all OAuth 2.0 grant types including:
     * - authorization_code
     * - client_credentials
     * - password
     * - refresh_token
     * - urn:ietf:params:oauth:grant-type:device_code
     *
     * @param Request $request Request with grant type specific parameters
     *
     * @throws OAuthServerException When request is invalid
     * @unauthenticated
     * @response array{
     *   access_token: string,
     *   token_type: string,
     *   expires_in: int,
     *   refresh_token?: string,
     *   scope?: string
     * }
     */
    #[Post('token', 'oauth.token')]
    public function token(Request $request): Response
    {
        $psrRequest = $this->psrFactory->createRequest($request);
        $psrResponse = $this->psrFactory->createResponse(new HttpFoundationResponse());

        try {
            return $this->convertResponse(
                $this->authorizationServer->respondToAccessTokenRequest($psrRequest, $psrResponse)
            );
        } catch (OAuthServerException $exception) {
            return $this->convertResponse(
                $exception->generateHttpResponse($psrResponse)
            );
        } catch (Exception $exception) {
            return response()->json(['error' => 'server_error'], 500);
        }
    }

    /**
     * Device Authorization Endpoint (RFC 8628)
     *
     * Initiates the device code flow by generating device and user codes.
     * Used for devices with limited input capabilities (smart TVs, etc).
     *
     * @param Request $request Request with client_id and optional scope
     *
     * @throws OAuthServerException When request is invalid
     * @unauthenticated
     * @response array{
     *   device_code: string,
     *   user_code: string,
     *   verification_uri: string,
     *   verification_uri_complete?: string,
     *   expires_in: int,
     *   interval: int
     * }
     */
    #[Post('device/authorize', 'oauth.device.authorize')]
    public function deviceAuthorize(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|string',
            'scope' => 'nullable|string',
        ]);

        $client = \App\Models\OAuth\Client::where('id', $request->input('client_id'))
            ->where('device_client', true)
            ->where('revoked', false)
            ->first();

        if (!$client) {
            throw OAuthServerException::invalidClient($this->psrFactory->createRequest($request));
        }

        $deviceCode = $this->generateDeviceCode();
        $userCode = $this->generateUserCode();
        $expiresIn = config('oauth.device_code_ttl', 600); // 10 minutes
        $interval = config('oauth.device_code_interval', 5); // 5 seconds

        $verificationUri = config('oauth.device_verification_uri', config('app.url') . '/device');
        $verificationUriComplete = $verificationUri . '?user_code=' . $userCode;

        DeviceCode::create([
            'device_code' => $deviceCode,
            'user_code' => $userCode,
            'client_id' => $client->id,
            'scopes' => $request->filled('scope') ? explode(' ', $request->input('scope')) : [],
            'verification_uri' => $verificationUri,
            'verification_uri_complete' => $verificationUriComplete,
            'expires_at' => now()->addSeconds($expiresIn),
            'interval' => $interval,
        ]);

        return response()->json([
            'device_code' => $deviceCode,
            'user_code' => $userCode,
            'verification_uri' => $verificationUri,
            'verification_uri_complete' => $verificationUriComplete,
            'expires_in' => $expiresIn,
            'interval' => $interval,
        ]);
    }

    /**
     * Device Verification Endpoint
     *
     * Allows users to enter their user code and approve device access.
     * This is the endpoint users visit on their phone/computer to authorize devices.
     *
     * @param Request $request Request with user_code parameter
     *
     * @unauthenticated
     * @response array{
     *   success: boolean,
     *   message: string
     * }
     */
    #[Get('device/verify', 'oauth.device.verify')]
    public function deviceVerify(Request $request): JsonResponse
    {
        $userCode = $request->input('user_code');

        if (!$userCode) {
            return response()->json([
                'success' => false,
                'message' => 'User code is required'
            ], 400);
        }

        $deviceCode = DeviceCode::where('user_code', $userCode)
            ->where('expires_at', '>', now())
            ->first();

        if (!$deviceCode) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired user code'
            ], 400);
        }

        if ($deviceCode->approved || $deviceCode->denied) {
            return response()->json([
                'success' => false,
                'message' => 'Device code already processed'
            ], 400);
        }

        return response()->json([
            'success' => true,
            'device_code' => $deviceCode,
            'client' => $deviceCode->client,
            'scopes' => $deviceCode->scopes,
        ]);
    }

    /**
     * Device Approval Endpoint
     *
     * Approves or denies device access after user authentication and consent.
     *
     * @param Request $request Request with user_code and action (approve/deny)
     *
     * @response array{
     *   success: boolean,
     *   message: string
     * }
     */
    #[Post('device/approve', 'oauth.device.approve', ['auth:sanctum'])]
    public function deviceApprove(Request $request): JsonResponse
    {
        $request->validate([
            'user_code' => 'required|string',
            'action' => 'required|in:approve,deny',
        ]);

        $deviceCode = DeviceCode::where('user_code', $request->input('user_code'))
            ->where('expires_at', '>', now())
            ->first();

        if (!$deviceCode) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired user code'
            ], 400);
        }

        if ($deviceCode->approved || $deviceCode->denied) {
            return response()->json([
                'success' => false,
                'message' => 'Device code already processed'
            ], 400);
        }

        if ($request->input('action') === 'approve') {
            $deviceCode->approve($request->user());
            $message = 'Device approved successfully';
        } else {
            $deviceCode->deny();
            $message = 'Device access denied';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /**
     * Token Introspection Endpoint (RFC 7662)
     *
     * Allows resource servers to query the authorization server about
     * the current state of an access token.
     *
     * @param Request $request Request with token parameter
     *
     * @throws OAuthServerException When request is invalid
     * @response array{
     *   active: boolean,
     *   client_id?: string,
     *   username?: string,
     *   scope?: string,
     *   exp?: int
     * }
     */
    #[Post('introspect', 'oauth.introspect')]
    public function introspect(Request $request): JsonResponse
    {
        $psrRequest = $this->psrFactory->createRequest($request);

        try {
            $this->resourceServer->validateAuthenticatedRequest($psrRequest);

            $token = \App\Models\OAuth\Token::where('token_id', $request->input('token'))->first();

            if (!$token || $token->isRevoked()) {
                return response()->json(['active' => false]);
            }

            return response()->json([
                'active' => true,
                'client_id' => $token->client_id,
                'username' => $token->user?->email,
                'scope' => implode(' ', $token->scopes ?? []),
                'exp' => $token->expires_at->timestamp,
            ]);

        } catch (OAuthServerException $exception) {
            return response()->json(['active' => false]);
        }
    }

    private function convertResponse(ResponseInterface $psrResponse): Response
    {
        return new Response(
            $psrResponse->getBody()->getContents(),
            $psrResponse->getStatusCode(),
            $psrResponse->getHeaders()
        );
    }

    private function generateDeviceCode(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function generateUserCode(): string
    {
        // Generate a readable user code (e.g., ABCD-EFGH)
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            if ($i === 4) {
                $code .= '-';
            }
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }
}
