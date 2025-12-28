<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Events\OAuth\{DeviceCodeApprovedEvent, DeviceCodeDeniedEvent, DeviceCodeRequestedEvent,};
use App\Http\Controllers\Controller;
use App\Models\Auth\OAuth\{DeviceCode as DeviceCodeModel};
use App\Models\Auth\OAuth\Client;
use App\Models\Auth\OAuth\Token;
use App\Modules\Auth\OAuth\Contracts\{ScopeRepositoryInterface};
use App\Modules\Auth\OAuth\Contracts\DeviceCodeRepositoryInterface;
use App\Modules\Auth\OAuth\Entities\UserEntity;
use App\Modules\Auth\OAuth\Psr7Factory;
use Dedoc\Scramble\Attributes\Group;
use Exception;
use Illuminate\Http\{Request, Response};
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Spatie\RouteAttributes\Attributes\{Get, Post, Prefix};

/**
 * OAuth 2.0 Server Controller
 *
 * Handles OAuth 2.0 flows including authorization code, client credentials,
 * password grant, device code flow, and token management.
 *
 * @tags OAuth
 */
#[Prefix('oauth')]
#[Group('Auth')]
class OAuthController extends Controller
{
    public function __construct(
        private readonly ResourceServer                $resourceServer,
        private readonly Psr7Factory                   $psr,
        private readonly DeviceCodeRepositoryInterface $deviceCodeRepository,
        private readonly ScopeRepositoryInterface      $scopeRepository,
    )
    {
    }

    /**
     * OAuth 2.0 Authorization Endpoint
     *
     * Handles the authorization code flow authorization endpoint.
     * Redirects users to consent screen and generates authorization codes.
     *
     * @param Request $request Request with client_id, redirect_uri, response_type, scope, and state
     *
     * @unauthenticated
     * @response 302
     */
    #[Get('authorize', 'oauth.authorize')]
    public function authorizeCodeFlow(Request $request)
    {
        $psrRequest = $this->psr->createRequest($request);
        $psrResponse = $this->psr->createResponse();
        $authorizationServer = app(AuthorizationServer::class);

        try {
            $authRequest = $authorizationServer->validateAuthorizationRequest($psrRequest);

            $user = $request->user();
            if (!$user) {
                return redirect('/login?' . http_build_query(['redirect' => $request->fullUrl()]));
            }

            $userEntity = new UserEntity();
            $userEntity->setIdentifier($user->id);
            $authRequest->setUser($userEntity);
            $authRequest->setAuthorizationApproved(true);

            return $this->psr->toLaravelResponse(
                $authorizationServer->completeAuthorizationRequest($authRequest, $psrResponse),
            );
        } catch (OAuthServerException $exception) {
            return $this->psr->toLaravelResponse($exception->generateHttpResponse($psrResponse));
        } catch (Exception $exception) {
            Log::error('OAuthController::authorizeCodeFlow failed', [
                'e.message' => $exception->getMessage(),
            ]);

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
     * @unauthenticated
     * @response array{
     *   access_token: string,
     *   token_type: string,
     *   expires_in: int,
     *   refresh_token?: string,
     *   scope?: string
     * }
     */
    #[Post('token', 'oauth.token', ['throttle:oauth-token'])]
    public function token(Request $request)
    {
        $psrRequest = $this->psr->createRequest($request);
        $psrResponse = $this->psr->createResponse();

        try {
            $authorizationServer = app(AuthorizationServer::class);
            return $this->psr->toLaravelResponse(
                $authorizationServer->respondToAccessTokenRequest($psrRequest, $psrResponse),
            );
        } catch (OAuthServerException $exception) {
            return $this->psr->toLaravelResponse($exception->generateHttpResponse($psrResponse));
        } catch (Exception $exception) {
            Log::error('OAuthController::token failed', [
                'e.message' => $exception->getMessage(),
            ]);

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
    public function deviceAuthorize(Request $request)
    {
        $request->validate([
            'client_id' => 'required|string',
            'scope'     => 'nullable|string',
        ]);

        $client = $this->getValidDeviceClient($request->input('client_id'));
        if (!$client) {
            throw OAuthServerException::invalidClient($this->psr->createRequest($request));
        }

        $deviceCodeEntity = $this->deviceCodeRepository->getNewDeviceCode();
        $deviceCodeString = $this->generateDeviceCode();
        $userCode = $this->generateUserCode();
        $expiresIn = config('oauth.device_code_ttl', 600);
        $interval = config('oauth.device_code_interval', 5);
        $verificationUri = route('oauth.device.verify');

        $deviceCodeEntity->setIdentifier($deviceCodeString);
        $deviceCodeEntity->setUserCode($userCode);
        $deviceCodeEntity->setVerificationUri($verificationUri);
        $deviceCodeEntity->setInterval($interval);
        $deviceCodeEntity->setExpiryDateTime(new \DateTimeImmutable("+$expiresIn seconds"));
        $deviceCodeEntity->setClient($client);
        $deviceCodeEntity->setUserApproved(false);

        $this->addScopesToEntity($deviceCodeEntity, $request->input('scope', ''));
        $this->deviceCodeRepository->persistDeviceCode($deviceCodeEntity);

        // Store in DB for event dispatch
        $storedDeviceCode = DeviceCodeModel::whereDeviceCode($deviceCodeString)->first();

        // Fire device code requested event
        if ($storedDeviceCode) {
            Event::dispatch(new DeviceCodeRequestedEvent(
                $storedDeviceCode,
                $client,
                $deviceCodeString,
                $userCode,
                $storedDeviceCode->scopes ?? [],
            ));
        }

        return response()->json([
            'device_code'               => $deviceCodeString,
            'user_code'                 => $userCode,
            'verification_uri'          => $verificationUri,
            'verification_uri_complete' => $verificationUri . '?user_code=' . $userCode,
            'expires_in'                => $expiresIn,
            'interval'                  => $interval,
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
    public function deviceVerify(Request $request)
    {
        $userCode = $request->input('user_code');

        if (!$userCode) {
            return $this->errorResponse('User code is required', 400);
        }

        $deviceCode = DeviceCodeModel::whereUserCode($userCode)
            ->where('expires_at', '>', now())
            ->first();

        if (!$deviceCode) {
            return $this->errorResponse('Invalid or expired user code', 400);
        }

        if ($deviceCode->approved || $deviceCode->denied) {
            return $this->errorResponse('Device code already processed', 400);
        }

        return response()->json([
            'success'     => true,
            'device_code' => $deviceCode,
            'client'      => $deviceCode->client,
            'scopes'      => $deviceCode->scopes,
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
    #[Post('device/approve', 'oauth.device.approve', ['auth:oauth'])]
    public function deviceApprove(Request $request)
    {
        $request->validate([
            'user_code' => 'required|string',
            'action'    => 'required|in:approve,deny',
        ]);

        $deviceCode = DeviceCodeModel::where('user_code', $request->input('user_code'))
            ->where('expires_at', '>', now())
            ->first();

        if (!$deviceCode) {
            return $this->errorResponse('Invalid or expired user code', 400);
        }

        if ($deviceCode->approved || $deviceCode->denied) {
            return $this->errorResponse('Device code already processed', 400);
        }

        if ($request->input('action') === 'approve') {
            $deviceCode->approve($request->user());

            // Fire device approved event
            Event::dispatch(new DeviceCodeApprovedEvent(
                $deviceCode,
                $request->user(),
                $deviceCode->client,
                $deviceCode->scopes ?? [],
            ));

            $message = 'Device approved successfully';
        } else {
            $deviceCode->deny();

            // Fire device denied event
            Event::dispatch(new DeviceCodeDeniedEvent(
                $deviceCode,
                $request->user(),
                $deviceCode->client,
            ));

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
     *   scope?: string,
     *   exp?: int
     * }
     */
    #[Post('introspect', 'oauth.introspect')]
    public function introspect(Request $request)
    {
        $psrRequest = $this->psr->createRequest($request);

        try {
            $this->resourceServer->validateAuthenticatedRequest($psrRequest);

            $token = Token::whereTokenId($request->input('token'))->first();

            if (!$token || $token->isRevoked()) {
                return response()->json(['active' => false]);
            }

            return response()->json([
                'active' => true,
                'scope'  => implode(' ', $token->scopes ?? []),
                'exp'    => $token->expires_at->timestamp,
            ]);
        } catch (OAuthServerException $exception) {
            Log::error('OAuthController::introspect failed', [
                'e.message' => $exception->getMessage(),
            ]);

            return response()->json(['active' => false]);
        }
    }

    private function errorResponse(string $message, int $status = 400)
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }

    private function getValidDeviceClient(string $clientId): ?Client
    {
        return Client::wherePublicId($clientId)
            ->whereDeviceClient(true)
            ->whereRevoked(false)
            ->first();
    }

    private function addScopesToEntity(
        DeviceCodeEntityInterface $entity,
        string                    $scopeString,
    ): void
    {
        if (empty($scopeString)) {
            return;
        }

        foreach (explode(' ', $scopeString) as $scopeIdentifier) {
            $scope = $this->scopeRepository->getScopeEntityByIdentifier($scopeIdentifier);
            if ($scope) {
                $entity->addScope($scope);
            }
        }
    }

    private function generateDeviceCode(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function generateUserCode(): string
    {
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
