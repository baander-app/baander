<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\OAuth;

use App\Auth\Application\Command\OAuth\ApproveDeviceCodeCommand;
use App\Auth\Application\Command\OAuth\CreateAuthorizationCodeCommand;
use App\Auth\Application\Command\OAuth\DenyDeviceCodeCommand;
use App\Auth\Application\Command\OAuth\ExchangeAuthorizationCodeCommand;
use App\Auth\Application\Command\OAuth\ExchangeDeviceCodeCommand;
use App\Auth\Application\Command\OAuth\ExchangeRefreshTokenCommand;
use App\Auth\Application\Command\OAuth\RequestDeviceAuthorizationCommand;
use App\Auth\Application\DTO\AuthorizationRequestDTO;
use App\Auth\Application\DTO\AuthorizationResponseDTO;
use App\Auth\Application\DTO\DeviceAuthorizationDTO;
use App\Auth\Application\DTO\PendingDeviceAuthorizationDTO;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\DTO\VerifiedDpopProof;
use App\Auth\Application\Exception\DeviceUserCodeException;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Auth\Application\Query\OAuth\GetAuthorizationRequestQuery;
use App\Auth\Application\Query\OAuth\GetDeviceAuthorizationQuery;
use App\Auth\Interface\Request\OAuth\DeviceApproveRequest;
use App\Auth\Interface\Request\OAuth\AuthorizationDecisionRequest;
use App\Auth\Interface\Request\OAuth\RevokeTokenRequest;
use App\Auth\Interface\Resource\OAuth\AuthorizationErrorResource;
use App\Auth\Interface\Resource\OAuth\AuthorizationRedirectResource;
use App\Auth\Interface\Resource\OAuth\AuthorizationRequestResource;
use App\Auth\Interface\Resource\OAuth\DeviceAuthorizationResource;
use App\Auth\Interface\Resource\OAuth\OAuthTokenResource;
use App\Auth\Interface\Resource\OAuth\PendingDeviceAuthorizationResource;
use App\Shared\Application\Http\BaanderHeader;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\OAuthError;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OAuth 2.0 authorization server endpoints for clients other than the first-party apps.
 *
 * The token and device authorization endpoints answer in the RFC 6749 and
 * RFC 8628 formats. The authorization and device verification endpoints back
 * the web app's consent and device pages: a browser redirect cannot carry the
 * DPoP-bound access token, so the page calls them and navigates itself.
 *
 * - authorization code grant with mandatory S256 PKCE (RFC 6749 section 4.1, RFC 7636)
 * - device authorization grant (RFC 8628) for devices without a browser, such as TV apps
 * - refresh token grant for tokens issued here (RFC 6749 section 6)
 * - token revocation (RFC 7009)
 *
 * Every token request carries a nonce-bearing DPoP proof, checked by
 * TokenEndpointDpopListener before the grant runs; the issued access and refresh
 * tokens are bound to its key, and to the X-Baander-Client-Fingerprint header
 * when the request sends one, exactly as first-party login tokens are.
 */
#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
#[Route('/api/oauth', name: 'oauth_')]
final class OAuthController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $bus,
        private readonly AccessTokenRepositoryInterface $accessTokenRepository,
        private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
        private readonly string $issuer,
    ) {
    }

    #[OA\Get(
        path: '/api/oauth/authorize',
        description: 'Backs the consent page at `APP_URL/oauth/authorize`, which RFC 8414 metadata advertises as the authorization endpoint. The page passes on the query parameters it received; this endpoint checks them for the signed-in user and describes the request. It never redirects. When the client or redirect URI is invalid, the error has no `redirect_uri`: show it to the user. Any other error carries `redirect_uri`, the client\'s redirect URI with `error`, `error_description`, `state` and `iss` (RFC 6749 section 4.1.2.1, RFC 9207); navigate there.',
        summary: 'Check an authorization request (RFC 6749 section 4.1.1)',
        parameters: [
            new OA\Parameter(name: 'response_type', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['code'])),
            new OA\Parameter(name: 'client_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'redirect_uri', description: 'Required unless the client registered exactly one redirect URI', in: 'query', schema: new OA\Schema(type: 'string', format: 'uri')),
            new OA\Parameter(name: 'scope', description: 'Space-separated scopes', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'state', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'code_challenge', description: 'BASE64URL(SHA256(code_verifier))', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'code_challenge_method', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['S256'])),
        ],
        responses: [
            new OA\Response(response: '200', description: 'A valid request awaiting the user\'s decision', content: new OA\JsonContent(ref: new Model(type: AuthorizationRequestResource::class))),
            new OA\Response(response: '400', description: 'Invalid request; with `redirect_uri` once the client and redirect URI are valid', content: new OA\JsonContent(ref: new Model(type: AuthorizationErrorResource::class))),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[Route('/authorize', name: 'authorize', methods: ['GET'])]
    public function authorize(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        $parameters = $request->query->all();
        $state = self::stringOrNull($parameters['state'] ?? null);

        try {
            $authorization = $this->dispatch(new GetAuthorizationRequestQuery(
                userId: Uuid::fromString($user->getId()),
                responseType: (string) self::stringOrNull($parameters['response_type'] ?? null),
                clientId: (string) self::stringOrNull($parameters['client_id'] ?? null),
                redirectUri: self::stringOrNull($parameters['redirect_uri'] ?? null),
                codeChallenge: self::stringOrNull($parameters['code_challenge'] ?? null),
                codeChallengeMethod: self::stringOrNull($parameters['code_challenge_method'] ?? null),
                scopes: self::scopes($parameters['scope'] ?? null),
            ));
        } catch (OAuthProtocolException $exception) {
            return $this->authorizationError($exception, $state);
        }
        assert($authorization instanceof AuthorizationRequestDTO);

        return self::noStore(new JsonResponse(AuthorizationRequestResource::from($authorization)));
    }

    #[OA\Post(
        path: '/api/oauth/authorize',
        description: 'Records the signed-in user\'s decision on the request the consent page showed, sent with the same parameters. Approval answers with the client\'s redirect URI carrying `code`, `state` and `iss` (RFC 9207); denial with `error=access_denied`, `state` and `iss`. The page navigates to `redirect_uri`. Errors follow GET.',
        summary: 'Approve or deny an authorization request (RFC 6749 section 4.1.2)',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: AuthorizationDecisionRequest::class))),
        responses: [
            new OA\Response(response: '200', description: 'Where to send the user agent', content: new OA\JsonContent(ref: new Model(type: AuthorizationRedirectResource::class))),
            new OA\Response(response: '400', description: 'Invalid request; with `redirect_uri` once the client and redirect URI are valid', content: new OA\JsonContent(ref: new Model(type: AuthorizationErrorResource::class))),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[Route('/authorize', name: 'authorize_decision', methods: ['POST'])]
    public function authorizeDecision(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        try {
            $parameters = self::parameters($request);
        } catch (OAuthProtocolException $exception) {
            return $this->authorizationError($exception, null);
        }
        $state = self::stringOrNull($parameters['state'] ?? null);
        $decision = self::stringOrNull($parameters['decision'] ?? null);
        if ($decision !== 'approve' && $decision !== 'deny') {
            return $this->authorizationError(OAuthProtocolException::invalidRequest('The decision must be "approve" or "deny".'), $state);
        }

        try {
            $answer = $this->dispatch(new CreateAuthorizationCodeCommand(
                userId: Uuid::fromString($user->getId()),
                responseType: (string) self::stringOrNull($parameters['response_type'] ?? null),
                clientId: (string) self::stringOrNull($parameters['client_id'] ?? null),
                redirectUri: self::stringOrNull($parameters['redirect_uri'] ?? null),
                codeChallenge: self::stringOrNull($parameters['code_challenge'] ?? null),
                codeChallengeMethod: self::stringOrNull($parameters['code_challenge_method'] ?? null),
                scopes: self::scopes($parameters['scope'] ?? null),
                approved: $decision === 'approve',
            ));
        } catch (OAuthProtocolException $exception) {
            return $this->authorizationError($exception, $state);
        }
        assert($answer instanceof AuthorizationResponseDTO);

        $redirect = $answer->code !== null
            ? $this->clientRedirect($answer->redirectUri, ['code' => $answer->code, 'state' => $state])
            : $this->clientRedirect($answer->redirectUri, ['error' => $answer->error, 'error_description' => $answer->errorDescription, 'state' => $state]);

        return self::noStore(new JsonResponse(['redirect_uri' => $redirect]));
    }

    #[OA\Post(
        path: '/api/oauth/token',
        description: 'Requires a DPoP proof carrying a server-issued nonce. A missing or invalid proof is answered with `invalid_dpop_proof`, a proof without a fresh nonce with `use_dpop_nonce`; both carry a `DPoP-Nonce` header. Every answer carries the nonce for the next proof and `Cache-Control: no-store`. The issued tokens are bound to the proof key, and to the `X-Baander-Client-Fingerprint` header when it is sent. Parameters may be form-encoded (RFC 6749) or JSON. Public clients authenticate with `client_id` alone; confidential clients add `client_secret`.',
        summary: 'OAuth 2.0 token endpoint (RFC 6749 section 3.2)',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['grant_type', 'client_id'],
                properties: [
                    new OA\Property(property: 'grant_type', type: 'string', enum: ['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:device_code']),
                    new OA\Property(property: 'client_id', type: 'string'),
                    new OA\Property(property: 'client_secret', description: 'Confidential clients only', type: 'string'),
                    new OA\Property(property: 'code', description: 'Authorization code (authorization_code grant)', type: 'string'),
                    new OA\Property(property: 'redirect_uri', description: 'The redirect URI of the authorization request (authorization_code grant)', type: 'string', format: 'uri'),
                    new OA\Property(property: 'code_verifier', description: 'PKCE code verifier (authorization_code grant)', type: 'string'),
                    new OA\Property(property: 'device_code', description: 'Device code (device_code grant)', type: 'string'),
                    new OA\Property(property: 'refresh_token', description: 'Refresh token (refresh_token grant)', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'DPoP-bound token pair (RFC 6749 section 5.1)', content: new OA\JsonContent(ref: new Model(type: OAuthTokenResource::class))),
            new OA\Response(response: '400', description: 'OAuth error, including the device polling answers authorization_pending, slow_down, access_denied and expired_token, and the DPoP answers invalid_dpop_proof and use_dpop_nonce', content: new OA\JsonContent(ref: new Model(type: OAuthError::class))),
            new OA\Response(response: '401', description: 'Client authentication failed, or the client is revoked', content: new OA\JsonContent(ref: new Model(type: OAuthError::class))),
        ],
    )]
    #[Route('/token', name: 'token', methods: ['POST'])]
    public function token(Request $request): Response
    {
        $proof = $request->attributes->get(VerifiedDpopProof::class);
        if (!$proof instanceof VerifiedDpopProof) {
            // TokenEndpointDpopListener answers requests without a valid proof before this runs.
            throw new \LogicException('The token endpoint ran without a verified DPoP proof.');
        }

        try {
            $parameters = self::parameters($request);
            $tokens = $this->dispatch($this->grantCommand($parameters, $proof, $request));
            assert($tokens instanceof TokenResponseDTO);
            $response = new JsonResponse(OAuthTokenResource::from($tokens));
        } catch (OAuthProtocolException $exception) {
            $response = $this->oauthError($exception);
        }

        $response->headers->set('DPoP-Nonce', $proof->nextNonce);

        return self::noStore($response);
    }

    #[OA\Post(
        path: '/api/oauth/revoke',
        summary: 'Revoke an access or refresh token (RFC 7009)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['token'], properties: [
                    new OA\Property(property: 'token', type: 'string', example: 'access-token-to-revoke'),
                    new OA\Property(property: 'tokenTypeHint', description: 'Hint for the token type (e.g. "access_token" or "refresh_token")', type: 'string'),
                ]),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Token revoked (always 200, even for invalid tokens per RFC 7009)', content: new OA\JsonContent()),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/revoke', name: 'revoke', methods: ['POST'])]
    public function revoke(#[MapRequestPayload] RevokeTokenRequest $payload): JsonResponse
    {
        try {
            if ($payload->tokenTypeHint === 'refresh_token') {
                $this->refreshTokenRepository->revokeRefreshToken($payload->token);
            } else {
                $this->accessTokenRepository->revokeAccessToken($payload->token);
            }
        } catch (\Throwable) {
            // Per RFC 7009, the revocation endpoint MUST return 200
            // even if the token is invalid — prevents token probing.
        }

        return new JsonResponse(null, Response::HTTP_OK);
    }

    #[OA\Post(
        path: '/api/oauth/device/authorize',
        description: 'Only clients registered as device clients may start the device flow. Parameters may be form-encoded (RFC 8628) or JSON. Show the user code and `verification_uri` (or encode `verification_uri_complete` in a QR code), then poll the token endpoint with the device code no faster than `interval` seconds.',
        summary: 'Device authorization request (RFC 8628 section 3.1)',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['client_id'],
                properties: [
                    new OA\Property(property: 'client_id', type: 'string'),
                    new OA\Property(property: 'scope', description: 'Space-separated scopes', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Device code issued (RFC 8628 section 3.2)', content: new OA\JsonContent(ref: new Model(type: DeviceAuthorizationResource::class))),
            new OA\Response(response: '400', description: 'Missing client_id, or the client may not use the device flow', content: new OA\JsonContent(ref: new Model(type: OAuthError::class))),
            new OA\Response(response: '401', description: 'Unknown or revoked client', content: new OA\JsonContent(ref: new Model(type: OAuthError::class))),
        ],
    )]
    #[Route('/device/authorize', name: 'device_authorize', methods: ['POST'])]
    public function deviceAuthorize(Request $request): JsonResponse
    {
        try {
            $parameters = self::parameters($request);
            $clientId = self::stringOrNull($parameters['client_id'] ?? null);
            if ($clientId === null || $clientId === '') {
                throw OAuthProtocolException::invalidRequest('The client_id parameter is required.');
            }
            $authorization = $this->dispatch(new RequestDeviceAuthorizationCommand(
                clientId: $clientId,
                scopes: self::scopes($parameters['scope'] ?? null),
            ));
        } catch (OAuthProtocolException $exception) {
            return $this->oauthError($exception);
        }
        assert($authorization instanceof DeviceAuthorizationDTO);

        return self::noStore(new JsonResponse(DeviceAuthorizationResource::from($authorization)));
    }

    #[OA\Get(
        path: '/api/oauth/device/verify',
        description: 'Backs the device page at `APP_URL/device`. Shows the signed-in user which client a user code belongs to before they approve or deny it. Case, spaces and dashes in the code do not matter. `scopes` are the scopes the issued token would carry.',
        summary: 'Look up a pending device authorization request (RFC 8628 section 3.3)',
        parameters: [
            new OA\Parameter(name: 'user_code', description: 'The user code displayed on the device', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'The pending request', content: new OA\JsonContent(
                required: ['data'],
                properties: [new OA\Property(property: 'data', ref: new Model(type: PendingDeviceAuthorizationResource::class))],
            )),
            new OA\Response(response: '400', description: 'Missing user code, or an unknown, expired or already decided one; `error.details.reason` is `user_code_required`, `invalid_user_code` or `device_already_processed`', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
        ],
    )]
    #[Route('/device/verify', name: 'device_verify', methods: ['GET'])]
    public function deviceVerify(Request $request): JsonResponse
    {
        $userCode = self::stringOrNull($request->query->all()['user_code'] ?? null);
        if ($userCode === null || trim($userCode) === '') {
            return $this->errorResponse($this->trans('errors.user_code_required', domain: 'auth'), details: ['reason' => 'user_code_required']);
        }

        try {
            $pending = $this->dispatch(new GetDeviceAuthorizationQuery($userCode));
        } catch (DeviceUserCodeException $exception) {
            return $this->userCodeError($exception);
        }
        assert($pending instanceof PendingDeviceAuthorizationDTO);

        return $this->successResponse(PendingDeviceAuthorizationResource::from($pending));
    }

    #[OA\Post(
        path: '/api/oauth/device/approve',
        description: 'Records the signed-in user\'s decision. Approval gives the device a token pair for this user at its next poll and notifies the user. Denial makes the next poll answer `access_denied`.',
        summary: 'Approve or deny a device authorization request (RFC 8628 section 3.3)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: DeviceApproveRequest::class)),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Device approved or denied',
                content: new OA\JsonContent(
                    required: ['data'],
                    properties: [
                        new OA\Property(property: 'data', required: ['decision', 'message'], properties: [
                            new OA\Property(property: 'decision', type: 'string', enum: ['approved', 'denied']),
                            new OA\Property(property: 'message', type: 'string', example: 'Device approved successfully.'),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '400', description: 'Unknown, expired or already decided user code; `error.details.reason` is `invalid_user_code` or `device_already_processed`', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/device/approve', name: 'device_approve', methods: ['POST'])]
    public function deviceApprove(#[MapRequestPayload] DeviceApproveRequest $payload): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }
        $userId = Uuid::fromString($user->getId());

        try {
            if ($payload->decision === 'approve') {
                $this->dispatch(new ApproveDeviceCodeCommand($payload->userCode, $userId));
                $decision = 'approved';
                $message = $this->trans('success.device_approved', domain: 'auth');
            } else {
                $this->dispatch(new DenyDeviceCodeCommand($payload->userCode, $userId));
                $decision = 'denied';
                $message = $this->trans('success.device_denied', domain: 'auth');
            }
        } catch (DeviceUserCodeException $exception) {
            return $this->userCodeError($exception);
        }

        return $this->successResponse(['decision' => $decision, 'message' => $message]);
    }

    // --- Internal ---

    /** @param array<string, mixed> $parameters */
    private function grantCommand(array $parameters, VerifiedDpopProof $proof, Request $request): object
    {
        $grantType = self::stringOrNull($parameters['grant_type'] ?? null);
        $clientId = self::stringOrNull($parameters['client_id'] ?? null);
        if ($grantType === null || $grantType === '') {
            throw OAuthProtocolException::invalidRequest('The grant_type parameter is required.');
        }
        if ($clientId === null || $clientId === '') {
            throw OAuthProtocolException::invalidClient('The client_id parameter is required.');
        }

        $clientSecret = self::stringOrNull($parameters['client_secret'] ?? null);
        $ipAddress = $request->getClientIp();
        $userAgent = $request->headers->get('User-Agent');
        $fingerprint = $request->headers->get(BaanderHeader::ClientFingerprint->value);

        return match ($grantType) {
            'authorization_code' => new ExchangeAuthorizationCodeCommand(
                clientId: $clientId,
                clientSecret: $clientSecret,
                code: self::stringOrNull($parameters['code'] ?? null),
                redirectUri: self::stringOrNull($parameters['redirect_uri'] ?? null),
                codeVerifier: self::stringOrNull($parameters['code_verifier'] ?? null),
                dpopJkt: $proof->jkt,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                clientFingerprint: $fingerprint,
            ),
            'urn:ietf:params:oauth:grant-type:device_code' => new ExchangeDeviceCodeCommand(
                clientId: $clientId,
                clientSecret: $clientSecret,
                deviceCode: self::stringOrNull($parameters['device_code'] ?? null),
                dpopJkt: $proof->jkt,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                clientFingerprint: $fingerprint,
            ),
            'refresh_token' => new ExchangeRefreshTokenCommand(
                clientId: $clientId,
                clientSecret: $clientSecret,
                refreshToken: self::stringOrNull($parameters['refresh_token'] ?? null),
                dpopJkt: $proof->jkt,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                clientFingerprint: $fingerprint,
            ),
            default => throw OAuthProtocolException::unsupportedGrantType($grantType),
        };
    }

    /**
     * Dispatches synchronously and rethrows the protocol and user-code errors the
     * handlers raise; anything else propagates to the kernel's error handling.
     */
    private function dispatch(object $message): mixed
    {
        try {
            return $this->bus->dispatch($message)->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $exception) {
            foreach ($exception->getWrappedExceptions() as $wrapped) {
                if ($wrapped instanceof OAuthProtocolException || $wrapped instanceof DeviceUserCodeException) {
                    throw $wrapped;
                }
            }

            throw $exception;
        }
    }

    private function oauthError(OAuthProtocolException $exception): JsonResponse
    {
        return self::noStore(new JsonResponse([
            'error' => $exception->error,
            'error_description' => $exception->getDescription(),
        ], $exception->statusCode));
    }

    /**
     * An authorization endpoint error for the consent page; never a redirect.
     *
     * Before the client and redirect URI are validated the page shows the error,
     * so the answer is 400 even for an unknown client (401 would read as an
     * expired session). Afterwards it carries the redirect URI with the error.
     */
    private function authorizationError(OAuthProtocolException $exception, ?string $state): JsonResponse
    {
        $body = [
            'error' => $exception->error,
            'error_description' => $exception->getDescription(),
        ];
        if ($exception->redirectUri !== null) {
            $body['redirect_uri'] = $this->clientRedirect($exception->redirectUri, [...$body, 'state' => $state]);
        }

        return self::noStore(new JsonResponse($body, Response::HTTP_BAD_REQUEST));
    }

    private function userCodeError(DeviceUserCodeException $exception): JsonResponse
    {
        return $this->errorResponse($this->trans('errors.' . $exception->reason, domain: 'auth'), details: ['reason' => $exception->reason]);
    }

    /**
     * The client's redirect URI with the response parameters and iss (RFC 9207), which lets
     * the client detect a mix-up between authorization servers.
     *
     * @param array<string, ?string> $parameters Null values are left out
     */
    private function clientRedirect(string $redirectUri, array $parameters): string
    {
        $parameters['iss'] = $this->issuer;
        $query = http_build_query(array_filter($parameters, static fn (?string $value): bool => $value !== null), '', '&', PHP_QUERY_RFC3986);
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return $redirectUri . $separator . $query;
    }

    /** RFC 6749 section 5.1: responses carrying tokens, codes or secrets must not be cached. */
    private static function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * Request parameters, form-encoded (RFC 6749) or as a JSON object.
     *
     * @return array<string, mixed>
     */
    private static function parameters(Request $request): array
    {
        $form = $request->request->all();
        if ($form !== []) {
            return $form;
        }

        try {
            return $request->getContent() === '' ? [] : $request->toArray();
        } catch (JsonException) {
            throw OAuthProtocolException::invalidRequest('The request body must be form-encoded or a JSON object.');
        }
    }

    /** @return string[] */
    private static function scopes(mixed $scope): array
    {
        if (!is_string($scope)) {
            return [];
        }

        return array_values(array_filter(explode(' ', $scope), static fn (string $s): bool => $s !== ''));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
