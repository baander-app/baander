<?php

declare(strict_types=1);

namespace App\Auth\Interface\Controller\OAuth;

use App\Auth\Application\Command\OAuth\ApproveDeviceCodeCommand;
use App\Auth\Application\Command\OAuth\CreateAuthorizationCodeCommand;
use App\Auth\Application\Command\OAuth\DenyDeviceCodeCommand;
use App\Auth\Application\Command\OAuth\ExchangeAuthorizationCodeCommand;
use App\Auth\Application\Command\OAuth\ExchangeDeviceCodeCommand;
use App\Auth\Application\Command\OAuth\ExchangeRefreshTokenCommand;
use App\Auth\Application\Command\OAuth\PushAuthorizationRequestCommand;
use App\Auth\Application\Command\OAuth\RequestDeviceAuthorizationCommand;
use App\Auth\Application\Command\OAuth\RevokeTokenCommand;
use App\Auth\Application\DTO\AuthorizationRequestDTO;
use App\Auth\Application\DTO\AuthorizationResponseDTO;
use App\Auth\Application\DTO\ClientCredentials;
use App\Auth\Application\DTO\DeviceAuthorizationDTO;
use App\Auth\Application\DTO\PendingDeviceAuthorizationDTO;
use App\Auth\Application\DTO\PushedAuthorizationRequestDTO;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\DTO\VerifiedDpopProof;
use App\Auth\Application\Exception\DeviceUserCodeException;
use App\Auth\Application\Exception\DpopStoreUnavailableException;
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
use App\Auth\Interface\Resource\OAuth\PushedAuthorizationRequestResource;
use App\Shared\Application\Exception\HandlerFailure;
use App\Shared\Application\Http\BaanderHeader;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use App\Shared\Interface\Controller\TranslatorTrait;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\OAuthError;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OAuth 2.0 authorization server endpoints for clients other than the first-party apps.
 *
 * The token, pushed authorization request and device authorization endpoints answer
 * in the RFC 6749, RFC 9126 and RFC 8628 formats. The authorization and device
 * verification endpoints back the web app's consent and device pages: a browser
 * redirect cannot carry the DPoP-bound access token, so the page calls them and
 * navigates itself.
 *
 * - authorization code grant with mandatory S256 PKCE (RFC 6749 section 4.1, RFC 7636), whose
 *   every request is pushed first (RFC 9126) and whose code is bound to the pushed DPoP key
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

    /** The largest pushed authorization request body accepted, in bytes. */
    private const int MAX_PAR_BODY_BYTES = 65536;

    public function __construct(
        private readonly Security $security,
        private readonly MessageBusInterface $bus,
        private readonly string $issuer,
    ) {
    }

    #[OA\Get(
        path: '/api/oauth/authorize',
        description: 'Backs the consent page at `APP_URL/oauth/authorize`, which RFC 8414 metadata advertises as the authorization endpoint. The client pushed its request to the pushed authorization request endpoint first; the page passes on only `client_id` and `request_uri`, and this endpoint describes the pushed request for the signed-in user. Looking it up does not use it up. It never redirects. When the client is invalid, or the pushed request is unknown, expired, already used or another client\'s (`invalid_request_uri`), the error has no `redirect_uri`: show it to the user. Any other error carries `redirect_uri`, the pushed redirect URI with `error`, `error_description`, the pushed `state` and `iss` (RFC 6749 section 4.1.2.1, RFC 9207); navigate there.',
        summary: 'Look up a pushed authorization request (RFC 9126 section 4)',
        parameters: [
            new OA\Parameter(name: 'client_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'request_uri', description: 'The request URI the pushed authorization request endpoint returned', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: '200', description: 'A pushed request awaiting the user\'s decision', content: new OA\JsonContent(ref: new Model(type: AuthorizationRequestResource::class))),
            new OA\Response(response: '400', description: 'Invalid request; with `redirect_uri` when the user cannot grant a valid pushed request', content: new OA\JsonContent(ref: new Model(type: AuthorizationErrorResource::class))),
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

        try {
            [$clientId, $requestUri] = self::pushedRequestReference($request->query->all());
            $authorization = $this->dispatch(new GetAuthorizationRequestQuery(Uuid::fromString($user->getId()), $clientId, $requestUri));
        } catch (OAuthProtocolException $exception) {
            return $this->authorizationError($exception);
        }
        assert($authorization instanceof AuthorizationRequestDTO);

        return self::noStore(new JsonResponse(AuthorizationRequestResource::from($authorization)));
    }

    #[OA\Post(
        path: '/api/oauth/authorize',
        description: 'Records the signed-in user\'s decision on the pushed request the consent page showed, named by the same `client_id` and `request_uri`. Either decision uses the request up. Approval answers with the pushed redirect URI carrying `code`, the pushed `state` and `iss` (RFC 9207); denial with `error=access_denied`, the pushed `state` and `iss`. The page navigates to `redirect_uri`. Errors follow GET; a request another decision already used answers `invalid_request_uri`.',
        summary: 'Approve or deny a pushed authorization request (RFC 6749 section 4.1.2)',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: AuthorizationDecisionRequest::class))),
        responses: [
            new OA\Response(response: '200', description: 'Where to send the user agent', content: new OA\JsonContent(ref: new Model(type: AuthorizationRedirectResource::class))),
            new OA\Response(response: '400', description: 'Invalid request; with `redirect_uri` when the user cannot grant a valid pushed request', content: new OA\JsonContent(ref: new Model(type: AuthorizationErrorResource::class))),
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
            [$clientId, $requestUri] = self::pushedRequestReference($parameters);
            $decision = self::stringOrNull($parameters['decision'] ?? null);
            if ($decision !== 'approve' && $decision !== 'deny') {
                throw OAuthProtocolException::invalidRequest('The decision must be "approve" or "deny".');
            }
            $answer = $this->dispatch(new CreateAuthorizationCodeCommand(Uuid::fromString($user->getId()), $clientId, $requestUri, $decision === 'approve'));
        } catch (OAuthProtocolException $exception) {
            return $this->authorizationError($exception);
        }
        assert($answer instanceof AuthorizationResponseDTO);

        $redirect = $answer->code !== null
            ? $this->clientRedirect($answer->redirectUri, ['code' => $answer->code, 'state' => $answer->state])
            : $this->clientRedirect($answer->redirectUri, ['error' => $answer->error, 'error_description' => $answer->errorDescription, 'state' => $answer->state]);

        return self::noStore(new JsonResponse(['redirect_uri' => $redirect]));
    }

    /** @throws ServiceUnavailableHttpException for a server fault while storing the request */
    #[OA\Post(
        path: '/api/oauth/par',
        description: 'Every authorization request is pushed here first. The client authenticates as at the token endpoint and binds the request to its DPoP key (RFC 9449 section 10.1) with a `DPoP` proof header carrying a server-issued nonce, the `dpop_jkt` parameter, or both naming the same key; the authorization code is later bound to that key. A proof is checked as at the token endpoint: `use_dpop_nonce` and `invalid_dpop_proof` answers carry a `DPoP-Nonce` header, and every answer to a verified proof carries the next nonce. Send the browser to the authorization endpoint with only `client_id` and the returned `request_uri`. Parameters may be form-encoded (RFC 9126) or JSON. Answers carry `Cache-Control: no-store`.',
        summary: 'Push an authorization request (RFC 9126 section 2)',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['response_type', 'code_challenge', 'code_challenge_method'],
                properties: [
                    new OA\Property(property: 'response_type', type: 'string', enum: ['code']),
                    new OA\Property(property: 'client_id', description: 'Required unless an HTTP Basic header names the client; it may repeat that client', type: 'string'),
                    new OA\Property(property: 'client_secret', description: 'Secret clients that do not send it with HTTP Basic', type: 'string'),
                    new OA\Property(property: 'redirect_uri', description: 'Required unless the client registered exactly one redirect URI', type: 'string', format: 'uri'),
                    new OA\Property(property: 'scope', description: 'Space-separated scopes', type: 'string'),
                    new OA\Property(property: 'state', description: 'At most 1024 bytes', type: 'string'),
                    new OA\Property(property: 'code_challenge', description: 'BASE64URL(SHA256(code_verifier))', type: 'string'),
                    new OA\Property(property: 'code_challenge_method', type: 'string', enum: ['S256']),
                    new OA\Property(property: 'dpop_jkt', description: 'JWK SHA-256 thumbprint of the DPoP key (RFC 7638); required without a DPoP proof header', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '201', description: 'The request URI for the authorization endpoint (RFC 9126 section 2.2)', content: new OA\JsonContent(ref: new Model(type: PushedAuthorizationRequestResource::class))),
            new OA\Response(response: '400', description: 'OAuth error, including invalid_dpop_proof when the proof and dpop_jkt name different keys, use_dpop_nonce, and request_not_supported for a request object', content: new OA\JsonContent(ref: new Model(type: OAuthError::class))),
            new OA\Response(response: '401', description: 'Client authentication failed, or the client is revoked', content: new OA\JsonContent(ref: new Model(type: OAuthError::class))),
            new OA\Response(response: '413', description: 'The request body is larger than 64 KiB (`invalid_request`)', content: new OA\JsonContent(ref: new Model(type: OAuthError::class))),
            new OA\Response(
                response: '503',
                description: 'The server could not store the request, for example while its database restarts, the rate limit or DPoP nonce store is unavailable, or the client\'s jwks_uri key set cannot be fetched; push again after the Retry-After delay. Carries no DPoP-Nonce header.',
                headers: [new OA\Header(header: 'Retry-After', description: 'Seconds to wait before retrying', schema: new OA\Schema(type: 'integer', example: 5))],
                content: new OA\JsonContent(ref: new Model(type: ApiError::class)),
            ),
        ],
    )]
    #[Route('/par', name: 'par', methods: ['POST'])]
    public function par(Request $request): Response
    {
        // TokenEndpointDpopListener verified the proof when the request carried one.
        $proof = $request->attributes->get(VerifiedDpopProof::class);
        $proof = $proof instanceof VerifiedDpopProof ? $proof : null;

        try {
            if (strlen($request->getContent()) > self::MAX_PAR_BODY_BYTES) {
                throw OAuthProtocolException::requestTooLarge(self::MAX_PAR_BODY_BYTES);
            }
            $pushed = $this->dispatch($this->pushCommand(self::parameters($request), $proof, $request));
            assert($pushed instanceof PushedAuthorizationRequestDTO);
            $response = new JsonResponse(PushedAuthorizationRequestResource::from($pushed), Response::HTTP_CREATED);
        } catch (OAuthProtocolException $exception) {
            $response = self::challengeBasic($request, $exception, $this->oauthError($exception));
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            // As at the token endpoint: a server fault says nothing about the request.
            throw new ServiceUnavailableHttpException(
                DpopStoreUnavailableException::RETRY_AFTER_SECONDS,
                'The pushed authorization request could not be stored.',
                HandlerFailure::cause($exception),
                headers: ['Cache-Control' => 'no-store'],
            );
        }

        if ($proof !== null) {
            $response->headers->set('DPoP-Nonce', $proof->nextNonce);
        }

        return self::noStore($response);
    }

    /** @throws ServiceUnavailableHttpException for a server fault during the grant */
    #[OA\Post(
        path: '/api/oauth/token',
        description: 'Requires a DPoP proof carrying a server-issued nonce. A missing or invalid proof is answered with `invalid_dpop_proof`, a proof without a fresh nonce with `use_dpop_nonce`; both carry a `DPoP-Nonce` header. Every answer carries the nonce for the next proof and `Cache-Control: no-store`. The issued tokens are bound to the proof key, and to the `X-Baander-Client-Fingerprint` header when it is sent. Parameters may be form-encoded (RFC 6749) or JSON. Every request names its client in `client_id` or in an HTTP Basic `Authorization` header. Public clients authenticate with `client_id` alone; confidential clients add `client_secret`, or `client_assertion` with `client_assertion_type`, by the one method they were registered with. A secret client sends its secret in the body or, with its client ID, in HTTP Basic (`client_secret_basic`, each part form-urlencoded first), never both; a failed Basic authentication is answered with a `WWW-Authenticate: Basic` challenge. A client assertion (RFC 7523 as updated by RFC 10070) is a JWT signed with one of the client\'s registered keys: `iss` and `sub` are the `client_id`, `aud` is the issuer identifier as its sole value, `exp` and `jti` are required, it lives at most 300 seconds by default, and its `jti` is accepted once. Its `typ` header is `client-authentication+jwt` or absent.',
        summary: 'OAuth 2.0 token endpoint (RFC 6749 section 3.2)',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['grant_type'],
                properties: [
                    new OA\Property(property: 'grant_type', type: 'string', enum: ['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:device_code']),
                    new OA\Property(property: 'client_id', description: 'Required unless an HTTP Basic header names the client; it may repeat that client', type: 'string'),
                    new OA\Property(property: 'client_secret', description: 'Secret clients that do not send it with HTTP Basic', type: 'string'),
                    new OA\Property(property: 'client_assertion', description: 'Signed JWT client assertion; confidential clients registered for private_key_jwt only', type: 'string'),
                    new OA\Property(property: 'client_assertion_type', description: 'Sent with client_assertion', type: 'string', enum: [ClientCredentials::JWT_BEARER_ASSERTION_TYPE]),
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
            new OA\Response(
                response: '503',
                description: 'The server could not decide on the grant, for example while its database restarts, the DPoP nonce store or client assertion jti store is unavailable, or the client\'s jwks_uri key set cannot be fetched; the presented token may still be valid, so retry after the Retry-After delay, with a newly signed client assertion. Carries no DPoP-Nonce header.',
                headers: [new OA\Header(header: 'Retry-After', description: 'Seconds to wait before retrying', schema: new OA\Schema(type: 'integer', example: 5))],
                content: new OA\JsonContent(ref: new Model(type: ApiError::class)),
            ),
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
            $response = self::challengeBasic($request, $exception, $this->oauthError($exception));
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            // A server fault, such as a database that is restarting, says nothing about
            // the grant; 503 with Retry-After keeps the client's tokens, as the first-party
            // refresh does. The exception subscriber renders it and keeps these headers.
            throw new ServiceUnavailableHttpException(
                DpopStoreUnavailableException::RETRY_AFTER_SECONDS,
                'The token endpoint could not complete the grant.',
                HandlerFailure::cause($exception),
                headers: ['Cache-Control' => 'no-store'],
            );
        }

        $response->headers->set('DPoP-Nonce', $proof->nextNonce);

        return self::noStore($response);
    }

    #[OA\Post(
        path: '/api/oauth/revoke',
        summary: 'Revoke an access or refresh token of the signed-in user and its chain (RFC 7009)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['token'], properties: [
                    new OA\Property(property: 'token', type: 'string', example: 'access-token-to-revoke'),
                    new OA\Property(property: 'tokenTypeHint', description: 'Hint for the token type (e.g. "access_token" or "refresh_token")', type: 'string'),
                ]),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Token revoked (always 200 for an unknown token or one of another user, per RFC 7009 section 2.2); the chain is revoked and the WebSocket connections opened with it are closed at once, or by the next socket reconciliation when the web server could not close them now', content: new OA\JsonContent()),
            new OA\Response(response: '422', description: 'Validation error', content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ValidationError::class))),
            new OA\Response(
                response: '503',
                description: 'The chain could not be revoked; the token is still live, so send the same request again after the Retry-After delay.',
                headers: [new OA\Header(header: 'Retry-After', description: 'Seconds to wait before retrying', schema: new OA\Schema(type: 'integer', example: 5))],
                content: new OA\JsonContent(ref: new Model(type: \App\Shared\Interface\DTO\ApiError::class)),
            ),
        ],
    )]
    #[Route('/revoke', name: 'revoke', methods: ['POST'])]
    public function revoke(#[MapRequestPayload] RevokeTokenRequest $payload): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserIdentityInterface) {
            return $this->unauthorized();
        }

        try {
            // Only a token of the authenticated user is revoked; the handler treats a
            // foreign token as unknown, so the answer tells the two apart in no way.
            // Revoking either token of a pair ends the session: the whole chain goes.
            $this->dispatch(new RevokeTokenCommand(
                tokenId: $payload->token,
                revokeChain: true,
                ownerUserId: $user->getId(),
            ));
        } catch (\Throwable $exception) {
            $cause = HandlerFailure::cause($exception);
            if ($cause instanceof \InvalidArgumentException) {
                // A string that is no token id is an unknown token: 200, like one that was
                // never issued (RFC 7009 section 2.2), so the endpoint probes nothing.
                return new JsonResponse(null, Response::HTTP_OK);
            }

            // A fault means the chain was not revoked. The client sends the same request
            // again; the chain revocation is idempotent.
            throw new ServiceUnavailableHttpException(
                DpopStoreUnavailableException::RETRY_AFTER_SECONDS,
                'The token could not be revoked.',
                $cause,
                headers: ['Cache-Control' => 'no-store'],
            );
        }

        return new JsonResponse(null, Response::HTTP_OK);
    }

    #[OA\Post(
        path: '/api/oauth/device/authorize',
        description: 'Only clients registered as device clients may start the device flow. A confidential client authenticates as at the token endpoint. Parameters may be form-encoded (RFC 8628) or JSON. Show the user code and `verification_uri` (or encode `verification_uri_complete` in a QR code), then poll the token endpoint with the device code no faster than `interval` seconds.',
        summary: 'Device authorization request (RFC 8628 section 3.1)',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['client_id'],
                properties: [
                    new OA\Property(property: 'client_id', type: 'string'),
                    new OA\Property(property: 'client_secret', description: 'Confidential clients registered for client_secret_post only', type: 'string'),
                    new OA\Property(property: 'client_assertion', description: 'Signed JWT client assertion; confidential clients registered for private_key_jwt only', type: 'string'),
                    new OA\Property(property: 'client_assertion_type', description: 'Sent with client_assertion', type: 'string', enum: [ClientCredentials::JWT_BEARER_ASSERTION_TYPE]),
                    new OA\Property(property: 'scope', description: 'Space-separated scopes', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: '200', description: 'Device code issued (RFC 8628 section 3.2)', content: new OA\JsonContent(ref: new Model(type: DeviceAuthorizationResource::class))),
            new OA\Response(response: '400', description: 'Missing client_id, or the client may not use the device flow', content: new OA\JsonContent(ref: new Model(type: OAuthError::class))),
            new OA\Response(response: '401', description: 'Unknown or revoked client, or client authentication failed', content: new OA\JsonContent(ref: new Model(type: OAuthError::class))),
        ],
    )]
    #[Route('/device/authorize', name: 'device_authorize', methods: ['POST'])]
    public function deviceAuthorize(Request $request): JsonResponse
    {
        try {
            $parameters = self::parameters($request);
            $authorization = $this->dispatch(new RequestDeviceAuthorizationCommand(
                credentials: self::credentials($request, $parameters, OAuthProtocolException::invalidRequest('The client_id parameter is required.')),
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
        if ($grantType === null || $grantType === '') {
            throw OAuthProtocolException::invalidRequest('The grant_type parameter is required.');
        }

        $credentials = self::credentials($request, $parameters, OAuthProtocolException::invalidClient('The client_id parameter is required.'));
        $ipAddress = $request->getClientIp();
        $userAgent = $request->headers->get('User-Agent');
        $fingerprint = $request->headers->get(BaanderHeader::ClientFingerprint->value);

        return match ($grantType) {
            'authorization_code' => new ExchangeAuthorizationCodeCommand(
                credentials: $credentials,
                code: self::stringOrNull($parameters['code'] ?? null),
                redirectUri: self::stringOrNull($parameters['redirect_uri'] ?? null),
                codeVerifier: self::stringOrNull($parameters['code_verifier'] ?? null),
                dpopJkt: $proof->jkt,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                clientFingerprint: $fingerprint,
            ),
            'urn:ietf:params:oauth:grant-type:device_code' => new ExchangeDeviceCodeCommand(
                credentials: $credentials,
                deviceCode: self::stringOrNull($parameters['device_code'] ?? null),
                dpopJkt: $proof->jkt,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                clientFingerprint: $fingerprint,
            ),
            'refresh_token' => new ExchangeRefreshTokenCommand(
                credentials: $credentials,
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
     * The client authentication a back-channel request presents, in the body or in an
     * HTTP Basic header. With Basic the header names the client, and a body `client_id`
     * may only repeat it.
     *
     * @param array<string, mixed> $parameters
     *
     * @throws OAuthProtocolException $missingClientId when no client is named, invalid_client for a malformed or contradicting Basic header
     */
    private static function credentials(Request $request, array $parameters, OAuthProtocolException $missingClientId): ClientCredentials
    {
        $bodyClientId = self::stringOrNull($parameters['client_id'] ?? null) ?? '';
        $basic = self::basicCredentials($request);
        if ($basic !== null && $bodyClientId !== '' && $bodyClientId !== $basic[0]) {
            throw OAuthProtocolException::invalidClient('The client_id parameter names another client than the Authorization header.');
        }
        $clientId = $basic[0] ?? $bodyClientId;
        if ($clientId === '') {
            throw $missingClientId;
        }

        return new ClientCredentials(
            clientId: $clientId,
            secret: self::stringOrNull($parameters['client_secret'] ?? null),
            assertion: self::stringOrNull($parameters['client_assertion'] ?? null),
            assertionType: self::stringOrNull($parameters['client_assertion_type'] ?? null),
            basicSecret: $basic[1] ?? null,
        );
    }

    /**
     * The client ID and secret of an HTTP Basic `Authorization` header, each form-urlencoded
     * before they were joined (RFC 6749 section 2.3.1); null when the request sent no Basic header.
     *
     * @return array{string, string}|null
     *
     * @throws OAuthProtocolException invalid_client when the header is not valid Basic credentials
     */
    private static function basicCredentials(Request $request): ?array
    {
        $header = $request->headers->get('Authorization') ?? '';
        if (strncasecmp($header, 'Basic ', 6) !== 0) {
            return null;
        }

        $decoded = base64_decode(trim(substr($header, 6)), strict: true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            throw OAuthProtocolException::invalidClient('The Authorization header does not carry valid Basic credentials.');
        }
        [$clientId, $secret] = array_map(urldecode(...), explode(':', $decoded, 2));
        if ($clientId === '') {
            throw OAuthProtocolException::invalidClient('The Authorization header does not name a client.');
        }

        return [$clientId, $secret];
    }

    /**
     * A failed authentication through HTTP Basic is answered with 401 and a Basic challenge
     * (RFC 6749 section 5.2).
     */
    private static function challengeBasic(Request $request, OAuthProtocolException $exception, JsonResponse $response): JsonResponse
    {
        if ($exception->error === 'invalid_client' && strncasecmp($request->headers->get('Authorization') ?? '', 'Basic ', 6) === 0) {
            $response->setStatusCode(Response::HTTP_UNAUTHORIZED);
            $response->headers->set('WWW-Authenticate', 'Basic realm="baander", charset="UTF-8"');
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @throws OAuthProtocolException
     */
    private function pushCommand(array $parameters, ?VerifiedDpopProof $proof, Request $request): PushAuthorizationRequestCommand
    {
        // RFC 9126 section 2.1: a pushed request cannot itself name a pushed request.
        if (array_key_exists('request_uri', $parameters)) {
            throw OAuthProtocolException::invalidRequest('A pushed authorization request cannot carry a request_uri.');
        }
        if (array_key_exists('request', $parameters)) {
            throw OAuthProtocolException::requestNotSupported();
        }

        return new PushAuthorizationRequestCommand(
            credentials: self::credentials($request, $parameters, OAuthProtocolException::invalidRequest('The client_id parameter is required.')),
            responseType: self::stringOrNull($parameters['response_type'] ?? null),
            redirectUri: self::stringOrNull($parameters['redirect_uri'] ?? null),
            scopes: self::scopes($parameters['scope'] ?? null),
            state: self::stringOrNull($parameters['state'] ?? null),
            codeChallenge: self::stringOrNull($parameters['code_challenge'] ?? null),
            codeChallengeMethod: self::stringOrNull($parameters['code_challenge_method'] ?? null),
            proofJkt: $proof?->jkt,
            dpopJkt: self::stringOrNull($parameters['dpop_jkt'] ?? null),
        );
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
     * While the client or the pushed request is invalid the page shows the error, so
     * the answer is 400 even for an unknown client (401 would read as an expired
     * session). An error about a valid pushed request carries its redirect URI with
     * the error and the pushed state.
     */
    private function authorizationError(OAuthProtocolException $exception): JsonResponse
    {
        $body = [
            'error' => $exception->error,
            'error_description' => $exception->getDescription(),
        ];
        if ($exception->redirectUri !== null) {
            $body['redirect_uri'] = $this->clientRedirect($exception->redirectUri, [...$body, 'state' => $exception->state]);
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

    /**
     * The client_id and request_uri naming a pushed request; the authorization endpoint reads no other parameter.
     *
     * @param array<string, mixed> $parameters
     * @return array{string, string}
     *
     * @throws OAuthProtocolException invalid_request, never redirected
     */
    private static function pushedRequestReference(array $parameters): array
    {
        $clientId = self::stringOrNull($parameters['client_id'] ?? null);
        $requestUri = self::stringOrNull($parameters['request_uri'] ?? null);
        if ($clientId === null || $clientId === '' || $requestUri === null || $requestUri === '') {
            throw OAuthProtocolException::invalidRequest('Authorization requests must be pushed first; client_id and request_uri are required.');
        }

        return [$clientId, $requestUri];
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
