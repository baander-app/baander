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
use App\Auth\Application\DTO\AuthorizationCodeDTO;
use App\Auth\Application\DTO\DeviceAuthorizationDTO;
use App\Auth\Application\DTO\PendingDeviceAuthorizationDTO;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\DTO\VerifiedDpopProof;
use App\Auth\Application\Exception\DeviceUserCodeException;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Auth\Application\Query\OAuth\GetDeviceAuthorizationQuery;
use App\Auth\Interface\Request\OAuth\DeviceApproveRequest;
use App\Auth\Interface\Request\OAuth\DeviceAuthorizeRequest;
use App\Auth\Interface\Request\OAuth\RevokeTokenRequest;
use App\Auth\Interface\Resource\TokenResource;
use App\Shared\Application\Http\BaanderHeader;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
        description: 'The signed-in user authorizes the client. PKCE with the S256 method is required for every client. On success, and on errors once the client and redirect URI are valid, the response redirects to the redirect URI with `code` or `error`, `state` and `iss` (RFC 9207). Cross-origin requests are refused (RFC 9700 section 2.6).',
        summary: 'OAuth 2.0 authorization endpoint (RFC 6749 section 3.1)',
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
            new OA\Response(response: '302', description: 'Redirect to the client with an authorization code or an error'),
            new OA\Response(response: '400', description: 'Missing or unregistered redirect URI', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\OAuthError::class))),
            new OA\Response(response: '401', description: 'Not authenticated (ApiError), or an unknown or revoked client (OAuthError invalid_client)', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\OAuthError::class))),
        ],
    )]
    #[OA\Post(
        path: '/api/oauth/authorize',
        description: 'Same as GET, with the parameters in the query string.',
        summary: 'OAuth 2.0 authorization endpoint (RFC 6749 section 3.1)',
        parameters: [
            new OA\Parameter(name: 'response_type', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['code'])),
            new OA\Parameter(name: 'client_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'redirect_uri', in: 'query', schema: new OA\Schema(type: 'string', format: 'uri')),
            new OA\Parameter(name: 'scope', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'state', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'code_challenge', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'code_challenge_method', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['S256'])),
        ],
        responses: [
            new OA\Response(response: '302', description: 'Redirect to the client with an authorization code or an error'),
            new OA\Response(response: '400', description: 'Missing or unregistered redirect URI', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\OAuthError::class))),
            new OA\Response(response: '401', description: 'Not authenticated (ApiError), or an unknown or revoked client (OAuthError invalid_client)', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\OAuthError::class))),
        ],
    )]
    #[Route('/authorize', name: 'authorize', methods: ['GET', 'POST'])]
    public function authorize(Request $request): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        $query = $request->query;
        $state = self::stringOrNull($query->all()['state'] ?? null);

        try {
            $code = $this->dispatch(new CreateAuthorizationCodeCommand(
                userId: Uuid::fromString($user->getId()),
                responseType: (string) self::stringOrNull($query->all()['response_type'] ?? null),
                clientId: (string) self::stringOrNull($query->all()['client_id'] ?? null),
                redirectUri: self::stringOrNull($query->all()['redirect_uri'] ?? null),
                codeChallenge: self::stringOrNull($query->all()['code_challenge'] ?? null),
                codeChallengeMethod: self::stringOrNull($query->all()['code_challenge_method'] ?? null),
                scopes: self::scopes($query->all()['scope'] ?? null),
            ));
        } catch (OAuthProtocolException $exception) {
            if ($exception->redirectUri === null) {
                return $this->oauthError($exception);
            }

            return $this->redirectToClient($exception->redirectUri, [
                'error' => $exception->error,
                'error_description' => $exception->getDescription(),
                'state' => $state,
            ]);
        }
        assert($code instanceof AuthorizationCodeDTO);

        return $this->redirectToClient($code->redirectUri, ['code' => $code->code, 'state' => $state]);
    }

    #[OA\Post(
        path: '/api/oauth/token',
        description: 'Requires a DPoP proof carrying a server-issued nonce; a proof without one is answered with `use_dpop_nonce` and a `DPoP-Nonce` header. Every answer carries the nonce for the next proof. The issued tokens are bound to the proof key, and to the `X-Baander-Client-Fingerprint` header when it is sent. Parameters may be form-encoded or JSON. Public clients authenticate with `client_id` alone; confidential clients add `client_secret`.',
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
            new OA\Response(response: '200', description: 'DPoP-bound token pair', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: new Model(type: TokenResource::class)),
            ])),
            new OA\Response(response: '400', description: 'OAuth error, including the device polling answers authorization_pending, slow_down, access_denied and expired_token, or a DPoP nonce challenge', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\OAuthError::class))),
            new OA\Response(response: '401', description: 'Client authentication failed', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\OAuthError::class))),
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
            $response = $this->successResponse(TokenResource::from($tokens));
        } catch (OAuthProtocolException $exception) {
            $response = $this->oauthError($exception);
        }

        $response->headers->set('DPoP-Nonce', $proof->nextNonce);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
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
        description: 'Only clients registered as device clients may start the device flow. Show the user code and verification URI, then poll the token endpoint with the device code no faster than `interval` seconds.',
        summary: 'Device authorization request (RFC 8628 section 3.1)',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: DeviceAuthorizeRequest::class)),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Device code issued',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'deviceCode', type: 'string'),
                            new OA\Property(property: 'userCode', type: 'string', example: 'BCDF-GHJK'),
                            new OA\Property(property: 'verificationUri', type: 'string', format: 'uri'),
                            new OA\Property(property: 'verificationUriComplete', type: 'string', format: 'uri'),
                            new OA\Property(property: 'expiresIn', type: 'integer', example: 900),
                            new OA\Property(property: 'interval', type: 'integer', example: 5),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '400', description: 'The client may not use the device flow', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\OAuthError::class))),
            new OA\Response(response: '401', description: 'Unknown or revoked client', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\OAuthError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/device/authorize', name: 'device_authorize', methods: ['POST'])]
    public function deviceAuthorize(#[MapRequestPayload] DeviceAuthorizeRequest $payload): JsonResponse
    {
        try {
            $authorization = $this->dispatch(new RequestDeviceAuthorizationCommand(
                clientId: $payload->clientId,
                scopes: self::scopes($payload->scope),
            ));
        } catch (OAuthProtocolException $exception) {
            return $this->oauthError($exception);
        }
        assert($authorization instanceof DeviceAuthorizationDTO);

        $response = $this->successResponse([
            'deviceCode' => $authorization->deviceCode,
            'userCode' => $authorization->userCode,
            'verificationUri' => $authorization->verificationUri,
            'verificationUriComplete' => $authorization->verificationUriComplete,
            'expiresIn' => $authorization->expiresIn,
            'interval' => $authorization->interval,
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[OA\Get(
        path: '/api/oauth/device/verify',
        description: 'Shows the signed-in user which client a user code belongs to before they approve or deny it. Case, spaces and dashes in the code do not matter.',
        summary: 'Look up a pending device authorization request (RFC 8628 section 3.3)',
        parameters: [
            new OA\Parameter(name: 'user_code', description: 'The user code displayed on the device', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'The pending request',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'userCode', type: 'string', example: 'BCDF-GHJK'),
                            new OA\Property(property: 'clientName', type: 'string'),
                            new OA\Property(property: 'scopes', type: 'array', items: new OA\Items(type: 'string')),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '400', description: 'Unknown, expired or already processed user code', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/device/verify', name: 'device_verify', methods: ['GET'])]
    public function deviceVerify(Request $request): JsonResponse
    {
        $userCode = self::stringOrNull($request->query->all()['user_code'] ?? null);
        if ($userCode === null || trim($userCode) === '') {
            return $this->errorResponse($this->trans('errors.user_code_required', domain: 'auth'));
        }

        try {
            $pending = $this->dispatch(new GetDeviceAuthorizationQuery($userCode));
        } catch (DeviceUserCodeException $exception) {
            return $this->errorResponse($this->trans('errors.' . $exception->reason, domain: 'auth'));
        }
        assert($pending instanceof PendingDeviceAuthorizationDTO);

        return $this->successResponse([
            'userCode' => $pending->userCode,
            'clientName' => $pending->clientName,
            'scopes' => $pending->scopes,
        ]);
    }

    #[OA\Post(
        path: '/api/oauth/device/approve',
        description: 'Approving gives the device a token pair for the signed-in user at its next poll and notifies the user. Denying makes the next poll answer `access_denied`.',
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
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'Device approved successfully.'),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: '400', description: 'Unknown, expired or already processed user code', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
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
            if ($payload->action === 'approve') {
                $this->dispatch(new ApproveDeviceCodeCommand($payload->userCode, $userId));
                $message = $this->trans('success.device_approved', domain: 'auth');
            } else {
                $this->dispatch(new DenyDeviceCodeCommand($payload->userCode, $userId));
                $message = $this->trans('success.device_denied', domain: 'auth');
            }
        } catch (DeviceUserCodeException $exception) {
            return $this->errorResponse($this->trans('errors.' . $exception->reason, domain: 'auth'));
        }

        return $this->successResponse(['message' => $message]);
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
        $response = new JsonResponse([
            'error' => $exception->error,
            'error_description' => $exception->getDescription(),
        ], $exception->statusCode);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @param array<string, ?string> $parameters Null values are left out
     */
    private function redirectToClient(string $redirectUri, array $parameters): RedirectResponse
    {
        // RFC 9207: the iss parameter lets the client detect a mix-up between servers.
        $parameters['iss'] = $this->issuer;
        $query = http_build_query(array_filter($parameters, static fn (?string $value): bool => $value !== null), '', '&', PHP_QUERY_RFC3986);
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return new RedirectResponse($redirectUri . $separator . $query, Response::HTTP_FOUND, ['Cache-Control' => 'no-store']);
    }

    /**
     * Token request parameters, form-encoded (RFC 6749) or as a JSON object.
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
