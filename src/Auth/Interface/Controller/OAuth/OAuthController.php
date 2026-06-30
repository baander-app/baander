<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\OAuth;

use App\Auth\Interface\Resource\TokenResource;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\DeviceCode;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface as DomainAccessTokenRepository;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface as DomainClientRepository;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface as DomainDeviceCodeRepository;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\OAuth\DpopNonceManager;
use App\Auth\Infrastructure\Security\OAuth\DpopProofValidator;
use App\Auth\Interface\Request\OAuth\DeviceApproveRequest;
use App\Auth\Interface\Request\OAuth\DeviceAuthorizeRequest;
use App\Auth\Interface\Request\OAuth\RevokeTokenRequest;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\ResourceServer;
use Nyholm\Psr7\Response as Psr7Response;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

#[OA\Tag(name: 'Auth', description: 'User registration, login, and profile management')]
#[Route('/api/oauth', name: 'oauth_')]
final class OAuthController
{
    use ApiResponsesTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly Security $security,
        private readonly AuthorizationServer $authorizationServer,
        private readonly ResourceServer $resourceServer,
        private readonly AccessTokenRepositoryInterface $accessTokenRepository,
        private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
        private readonly DeviceCodeRepositoryInterface $deviceCodeRepository,
        private readonly DomainAccessTokenRepository $domainAccessTokenRepository,
        private readonly DomainClientRepository $clientRepository,
        private readonly DomainDeviceCodeRepository $domainDeviceCodeRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly HttpMessageFactoryInterface $psrHttpFactory,
        private readonly HttpFoundationFactoryInterface $httpFoundationFactory,
        private readonly DpopProofValidator $dpopProofValidator,
        private readonly DpopNonceManager $dpopNonceManager,
        private readonly JsonEncoder $jsonEncoder,
        private readonly string $resourceServerUri,
    ) {
    }

    #[OA\Get(
        path: '/api/oauth/authorize',
        summary: 'OAuth 2.0 Authorization endpoint (RFC 6749 §3.1)',
        security: [],
        parameters: [
            new OA\Parameter(name: 'response_type', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['code'])),
            new OA\Parameter(name: 'client_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'redirect_uri', in: 'query', schema: new OA\Schema(type: 'string', format: 'uri')),
            new OA\Parameter(name: 'scope', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'state', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '302', description: 'Redirect with authorization code'),
            new OA\Response(response: '400', description: 'Invalid OAuth request', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\OAuthError::class))),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[OA\Post(
        path: '/api/oauth/authorize',
        summary: 'OAuth 2.0 Authorization endpoint (RFC 6749 §3.1)',
        security: [],
        parameters: [
            new OA\Parameter(name: 'response_type', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['code'])),
            new OA\Parameter(name: 'client_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'redirect_uri', in: 'query', schema: new OA\Schema(type: 'string', format: 'uri')),
            new OA\Parameter(name: 'scope', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'state', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '302', description: 'Redirect with authorization code'),
            new OA\Response(response: '400', description: 'Invalid OAuth request', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\OAuthError::class))),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/authorize', name: 'authorize', methods: ['GET', 'POST'])]
    public function authorize(Request $request): Response
    {
        // --- Unit 3: Mandatory PKCE enforcement (RFC 9700 §2.1 / RFC 7636) ---
        // Reject authorization code requests without PKCE before delegating to League.
        $responseType = $request->query->get('response_type');
        if ($responseType === 'code') {
            $codeChallenge = $request->query->get('code_challenge');
            if ($codeChallenge === null || $codeChallenge === '') {
                return new JsonResponse([
                    'error' => 'invalid_request',
                    'error_description' => 'PKCE code_challenge is required for authorization code requests.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $codeChallengeMethod = $request->query->get('code_challenge_method');

            // If method is absent, treat as implicit plain (RFC 7636 §4.3).
            // If method is explicitly "plain", also reject.
            if ($codeChallengeMethod === null || $codeChallengeMethod === '' || $codeChallengeMethod === 'plain') {
                return new JsonResponse([
                    'error' => 'invalid_request',
                    'error_description' => 'PKCE code_challenge_method must be S256.',
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        $psrRequest = $this->psrHttpFactory->createRequest($request);
        $psrResponse = new Psr7Response();

        try {
            $authRequest = $this->authorizationServer->validateAuthorizationRequest($psrRequest);

            $securityUser = $this->security->getUser();
            if ($securityUser === null) {
                return $this->unauthorized();
            }

            $user = $this->userRepository->findByUuid(
                Uuid::fromString($securityUser->getId()),
            );

            if ($user === null) {
                return $this->errorResponse($this->trans('errors.user_not_found', domain: 'auth'), Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $authRequest->setUser(new \League\OAuth2\Server\Entities\UserEntity(
                $user->getId()->toString(),
            ));

            $authRequest->setAuthorizationApproved(true);

            $psrResponse = $this->authorizationServer->completeAuthorizationRequest($authRequest, $psrResponse);
            $response = $this->httpFoundationFactory->createResponse($psrResponse);

            // --- Unit 4: Issuer identification (RFC 9207) ---
            // Append iss parameter to authorization response redirect URI.
            $location = $response->headers->get('Location');
            if ($location !== null && $response->isRedirection()) {
                $parsed = parse_url($location);
                $separator = isset($parsed['query']) && $parsed['query'] !== '' ? '&' : '?';
                $modifiedLocation = $location . $separator . 'iss=' . rawurlencode($this->resourceServerUri);
                $response->headers->set('Location', $modifiedLocation);
            }

            return $response;
        } catch (OAuthServerException $exception) {
            return $this->httpFoundationFactory->createResponse(
                $exception->generateHttpResponse($psrResponse),
            );
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.internal', domain: 'auth'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[OA\Post(
        path: '/api/oauth/token',
        description: 'Supports authorization_code, refresh_token, and client_credentials grant types.',
        summary: 'OAuth 2.0 Token endpoint (RFC 6749 §3.2)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'grant_type', type: 'string', enum: ['authorization_code', 'refresh_token', 'client_credentials', 'urn:ietf:params:oauth:grant-type:device_code']),
                    new OA\Property(property: 'code', description: 'Authorization code (for authorization_code grant)', type: 'string'),
                    new OA\Property(property: 'redirect_uri', type: 'string', format: 'uri'),
                    new OA\Property(property: 'client_id', type: 'string'),
                    new OA\Property(property: 'client_secret', type: 'string'),
                    new OA\Property(property: 'refresh_token', description: 'Refresh token (for refresh_token grant)', type: 'string'),
                    new OA\Property(property: 'scope', type: 'string'),
                    new OA\Property(property: 'device_code', description: 'Device code (for device_code grant)', type: 'string'),
                ],
                type: 'object',
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Access token',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'accessToken', type: 'string'),
                            new OA\Property(property: 'tokenType', type: 'string', example: 'Bearer'),
                            new OA\Property(property: 'expiresIn', type: 'integer', example: 3600),
                            new OA\Property(property: 'refreshToken', type: 'string'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '400', description: 'Invalid grant or client credentials', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\OAuthError::class))),
        ],
    )]
    #[Route('/token', name: 'token', methods: ['POST'])]
    public function token(Request $request): Response
    {
        // Validate DPoP proof
        $dpopHeader = $request->headers->get('DPoP');
        if ($dpopHeader === null || $dpopHeader === '') {
            return $this->errorResponse('DPoP proof header is required.', Response::HTTP_BAD_REQUEST);
        }

        // Nonce challenge-response (Auth0-style): require nonce in proof
        $proofNonce = $this->dpopProofValidator->extractNonce($dpopHeader);
        if ($proofNonce === null || $proofNonce === '') {
            return $this->dpopNonceManager->createChallengeResponse();
        }

        if (!$this->dpopNonceManager->isValid($proofNonce)) {
            return $this->dpopNonceManager->createChallengeResponse(
                'Authorization server requires a fresh nonce in DPoP proof.',
            );
        }

        $result = $this->dpopProofValidator->validate($dpopHeader, $request);
        if (!$result->isValid()) {
            return $this->dpopNonceManager->createChallengeResponse(
                $result->getErrorDescription() ?? $result->getError() ?? 'DPoP proof validation failed.',
            );
        }

        $request->attributes->set('_dpop_jkt', $result->getJkt());

        try {
            $psrResponse = $this->authorizationServer->respondToAccessTokenRequest(
                $this->psrHttpFactory->createRequest($request),
                new Psr7Response(),
            );
        } catch (OAuthServerException $exception) {
            return $this->httpFoundationFactory->createResponse(
                $exception->generateHttpResponse(new Psr7Response()),
            );
        } catch (\Throwable) {
            return $this->errorResponse($this->trans('errors.internal', domain: 'auth'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Normalize the League RFC 6749 snake_case response to the project-standard
        // {data: {accessToken, refreshToken, expiresIn, tokenType}} envelope.
        $body = (string) $psrResponse->getBody();
        $data = $this->jsonEncoder->decode($body, 'json');

        $nonce = $this->dpopNonceManager->generateNonce();
        $this->dpopNonceManager->storeNonce($nonce);

        $response = $this->successResponse(TokenResource::fromOAuthResponse($data));
        $response->headers->set('DPoP-Nonce', $nonce);

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
        path: '/api/oauth/introspect',
        summary: 'Introspect an access token (RFC 7662)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'token', description: 'The access token to introspect', type: 'string'),
                ],
                type: 'object',
            ),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Token introspection result',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'active', type: 'boolean'),
                        new OA\Property(property: 'scope', type: 'string', nullable: true),
                        new OA\Property(property: 'exp', description: 'Expiration timestamp', type: 'integer', nullable: true),
                        new OA\Property(property: 'client_id', type: 'string', nullable: true),
                        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Invalid or expired token', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/introspect', name: 'introspect', methods: ['POST'])]
    public function introspect(Request $request): JsonResponse
    {
        $psrRequest = $this->psrHttpFactory->createRequest($request);

        try {
            $psrRequest = $this->resourceServer->validateAuthenticatedRequest($psrRequest);

            $tokenId = $psrRequest->getAttribute('oauth_user_id');
        } catch (OAuthServerException) {
            return new JsonResponse(['active' => false]);
        } catch (\Throwable) {
            return new JsonResponse(['active' => false]);
        }

        // The resource server validates the token but doesn't expose scope/expiry
        // directly. Use the domain repo for a full introspection response.
        $domain = $this->findDomainAccessToken($tokenId);

        if ($domain === null || $domain->isRevoked() || $domain->isExpired()) {
            return new JsonResponse(['active' => false]);
        }

        return new JsonResponse([
            'active' => true,
            'scope' => implode(' ', $domain->getScopeIdentifiers()),
            'exp' => $domain->getExpiresAt()?->getTimestamp(),
            'client_id' => $domain->getClient()->getPublicId()->toString(),
            'aud' => $this->resourceServerUri,
            'token_type' => 'Bearer',
        ]);
    }

    #[OA\Post(
        path: '/api/oauth/device/authorize',
        summary: 'Request device authorization code (RFC 8628 §3.1)',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['clientId'], properties: [
                    new OA\Property(property: 'clientId', type: 'string', example: 'client-uuid'),
                    new OA\Property(property: 'scope', description: 'Requested OAuth scopes', type: 'string'),
                ]),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Device code created',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'deviceCode', type: 'string'),
                            new OA\Property(property: 'userCode', type: 'string'),
                            new OA\Property(property: 'verificationUri', type: 'string', format: 'uri'),
                            new OA\Property(property: 'verificationUriComplete', type: 'string', format: 'uri', nullable: true),
                            new OA\Property(property: 'expiresIn', type: 'integer', example: 600),
                            new OA\Property(property: 'interval', type: 'integer', example: 5),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Invalid client', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/device/authorize', name: 'device_authorize', methods: ['POST'])]
    public function deviceAuthorize(#[MapRequestPayload] DeviceAuthorizeRequest $payload): JsonResponse
    {
        $client = $this->validateDeviceClient($payload->clientId);
        if ($client === null) {
            return $this->unauthorized($this->trans('errors.client_auth_failed', domain: 'auth'));
        }

        $deviceCodeEntity = $this->deviceCodeRepository->getNewDeviceCode();

        // Persist via domain repo — convert to domain model and save
        $scopes = [];
        if ($payload->scope !== '') {
            foreach (explode(' ', $payload->scope) as $scopeId) {
                if ($scopeId !== '') {
                    $scopes[] = $scopeId;
                }
            }
        }

        $deviceCode = DeviceCode::create(
            $client,
            $deviceCodeEntity->getUserCode(),
            $deviceCodeEntity->getVerificationUri(),
            $deviceCodeEntity->getVerificationUriComplete() !== ''
                ? $deviceCodeEntity->getVerificationUriComplete()
                : null,
            array_map(
                fn (string $id) => new \App\Auth\Domain\Model\OAuth\ValueObject\Scope($id),
                $scopes,
            ),
            new \DateInterval('PT15M'),
            $deviceCodeEntity->getInterval(),
        );

        $this->domainDeviceCodeRepository->save($deviceCode);

        return $this->successResponse([
            'deviceCode' => $deviceCode->getDeviceCode()->toString(),
            'userCode' => $deviceCode->getUserCode(),
            'verificationUri' => $deviceCode->getVerificationUri(),
            'verificationUriComplete' => $deviceCode->getVerificationUriComplete(),
            'expiresIn' => 600,
            'interval' => $deviceCode->getInterval(),
        ]);
    }

    #[OA\Get(
        path: '/api/oauth/device/verify',
        summary: 'Check device authorization status by user code (RFC 8628)',
        security: [],
        parameters: [
            new OA\Parameter(name: 'user_code', description: 'The user code displayed on the device', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: 'Device code info',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'clientName', type: 'string'),
                            new OA\Property(property: 'scopes', type: 'array', items: new OA\Items(type: 'string')),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '400', description: 'Invalid or expired user code', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
        ],
    )]
    #[Route('/device/verify', name: 'device_verify', methods: ['GET'])]
    public function deviceVerify(Request $request): JsonResponse
    {
        $userCode = $request->query->get('user_code');

        if ($userCode === null || trim((string) $userCode) === '') {
            return $this->errorResponse($this->trans('errors.user_code_required', domain: 'auth'));
        }

        $deviceCode = $this->domainDeviceCodeRepository->findByUserCode((string) $userCode);

        if ($deviceCode === null || $deviceCode->isExpired()) {
            return $this->errorResponse($this->trans('errors.invalid_user_code', domain: 'auth'));
        }

        if ($deviceCode->isApproved() || $deviceCode->isDenied()) {
            return $this->errorResponse($this->trans('errors.device_already_processed', domain: 'auth'));
        }

        return $this->successResponse([
            'clientName' => $deviceCode->getClient()->getName(),
            'scopes' => $deviceCode->getScopeIdentifiers(),
        ]);
    }

    #[OA\Post(
        path: '/api/oauth/device/approve',
        summary: 'Approve or deny a device authorization request (RFC 8628)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['userCode', 'action'], properties: [
                    new OA\Property(property: 'userCode', type: 'string', example: 'ABCD-EFGH'),
                    new OA\Property(property: 'action', type: 'string', example: 'approve', enum: ['approve', 'deny']),
                ]),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Device approved or denied',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'message', type: 'string', example: 'Device approved.'),
                        ], type: 'object'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: '401', description: 'Not authenticated', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class))),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
        ],
    )]
    #[Route('/device/approve', name: 'device_approve', methods: ['POST'])]
    public function deviceApprove(#[MapRequestPayload] DeviceApproveRequest $payload): JsonResponse
    {
        $deviceCode = $this->domainDeviceCodeRepository->findByUserCode($payload->userCode);

        if ($deviceCode === null || $deviceCode->isExpired()) {
            return $this->errorResponse($this->trans('errors.invalid_user_code', domain: 'auth'));
        }

        if ($deviceCode->isApproved() || $deviceCode->isDenied()) {
            return $this->errorResponse($this->trans('errors.device_already_processed', domain: 'auth'));
        }

        if ($payload->action === 'approve') {
            $securityUser = $this->security->getUser();
            if ($securityUser === null) {
                return $this->unauthorized();
            }

            $user = $this->userRepository->findByUuid(
                Uuid::fromString($securityUser->getId()),
            );

            if ($user === null) {
                return $this->errorResponse($this->trans('errors.user_not_found', domain: 'auth'), Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $deviceCode->approve($user);
            $this->domainDeviceCodeRepository->save($deviceCode);

            $message = $this->trans('success.device_approved', domain: 'auth');
        } else {
            $deviceCode->deny();
            $this->domainDeviceCodeRepository->save($deviceCode);

            $message = $this->trans('success.device_denied', domain: 'auth');
        }

        return $this->successResponse([
            'message' => $message,
        ]);
    }

    // --- Internal ---

    private function findDomainAccessToken(?string $tokenId): ?AccessToken
    {
        if ($tokenId === null || trim($tokenId) === '') {
            return null;
        }

        try {
            return $this->domainAccessTokenRepository->findByTokenId(
                TokenId::fromString($tokenId),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function validateDeviceClient(string $clientId): ?\App\Auth\Domain\Model\OAuth\Client
    {
        try {
            $publicId = new PublicId($clientId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $client = $this->clientRepository->findClientByPublicId($publicId);

        if ($client === null || $client->isRevoked()) {
            return null;
        }

        if (!$client->isDeviceClient()) {
            return null;
        }

        return $client;
    }
}
