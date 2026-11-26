<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\DTO\ValidatedAuthorizationRequest;
use App\Auth\Application\DTO\ValidatedPushedAuthorizationRequest;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\ScopeAllowlist;
use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientType;
use App\Auth\Domain\Model\OAuth\PushedAuthorizationRequest;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\RedirectUri;
use App\Auth\Domain\Repository\OAuth\PushedAuthorizationRequestRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Validates authorization requests (RFC 6749 section 4.1.1) in two steps.
 *
 * When a client pushes a request (RFC 9126), every check that needs no user runs: the
 * redirect URI, the response type, the exclusion of device clients and of the first-party
 * client, PKCE and the scope allowlist. PKCE is mandatory for every client and only S256
 * is accepted (RFC 9700 section 2.1.1). Errors at that step answer the client directly.
 *
 * When the signed-in user's consent page looks the request up or decides on it, the
 * pushed request is resolved from its request URI, and only the checks that depend on
 * the user run: a disabled account, and a personal access client serving only its
 * owner. An unusable request URI is shown to the user and never redirected; a user
 * check fails at the pushed redirect URI with the pushed state.
 */
final readonly class AuthorizationRequestValidator
{
    public function __construct(
        private OAuthClientAuthenticator $clientAuthenticator,
        private UserRepositoryInterface $userRepository,
        private ScopeAllowlist $scopeAllowlist,
        private PushedAuthorizationRequestRepositoryInterface $pushedRequests,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The checks of a pushed request that need no user.
     *
     * @param string[] $scopes
     *
     * @throws OAuthProtocolException without a redirect URI
     */
    public function validatePush(
        Client $client,
        ?string $responseType,
        ?string $redirectUri,
        ?string $codeChallenge,
        ?string $codeChallengeMethod,
        array $scopes,
    ): ValidatedPushedAuthorizationRequest {
        $resolvedRedirectUri = $this->resolveRedirectUri($client, $redirectUri);

        if ($responseType !== 'code') {
            throw OAuthProtocolException::unsupportedResponseType();
        }
        if ($client->isDeviceClient()) {
            throw OAuthProtocolException::unauthorizedClient('Device clients use the device authorization grant.');
        }
        if ($client->getType() === ClientType::FirstParty) {
            throw OAuthProtocolException::unauthorizedClient('The first-party client signs in through password and passkey login.');
        }
        if ($codeChallenge === null || $codeChallenge === '') {
            throw OAuthProtocolException::invalidRequest('PKCE code_challenge is required.');
        }
        if ($codeChallengeMethod !== AuthCode::CODE_CHALLENGE_METHOD) {
            throw OAuthProtocolException::invalidRequest('PKCE code_challenge_method must be S256.');
        }
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $codeChallenge) !== 1) {
            throw OAuthProtocolException::invalidRequest('The code_challenge is not a base64url SHA-256 digest.');
        }

        return new ValidatedPushedAuthorizationRequest($client, $resolvedRedirectUri, $codeChallenge, $codeChallengeMethod, $this->scopeAllowlist->grantable($scopes));
    }

    /**
     * Resolves the pushed request and runs the checks that depend on the signed-in user.
     *
     * @throws OAuthProtocolException
     */
    public function validate(Uuid $userId, string $clientId, string $requestUri): ValidatedAuthorizationRequest
    {
        [$client, $pushedRequest] = $this->resolvePushedRequest($clientId, $requestUri);

        try {
            return $this->validateForUser($userId, $client, $pushedRequest);
        } catch (OAuthProtocolException $exception) {
            throw $exception->redirectTo($pushedRequest->getRedirectUri(), $pushedRequest->getState());
        }
    }

    /**
     * The client and the pushed request a request URI names, while the request is usable.
     *
     * Looking the request up does not use it up. Errors here are never redirected.
     *
     * @return array{Client, PushedAuthorizationRequest}
     *
     * @throws OAuthProtocolException invalid_client, or invalid_request_uri for a request that is
     *                                unknown, malformed, expired, used up or another client's
     */
    public function resolvePushedRequest(string $clientId, string $requestUri): array
    {
        $client = $this->clientAuthenticator->identify($clientId);
        $pushedRequest = $this->findPushedRequest($requestUri);

        if ($pushedRequest === null || !$pushedRequest->getClientId()->equals($client->getId())) {
            throw OAuthProtocolException::invalidRequestUri('The request_uri is unknown or was pushed by another client.');
        }
        if ($pushedRequest->isConsumed()) {
            throw OAuthProtocolException::invalidRequestUri('The request_uri was already used.');
        }
        if ($pushedRequest->isExpired($this->clock->now())) {
            throw OAuthProtocolException::invalidRequestUri('The request_uri has expired.');
        }

        return [$client, $pushedRequest];
    }

    private function findPushedRequest(string $requestUri): ?PushedAuthorizationRequest
    {
        if (!str_starts_with($requestUri, PushedAuthorizationRequest::REQUEST_URI_PREFIX)) {
            return null;
        }

        try {
            $requestId = substr($requestUri, strlen(PushedAuthorizationRequest::REQUEST_URI_PREFIX)) |> TokenId::fromString(...);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $this->pushedRequests->findByRequestId($requestId);
    }

    /** @throws OAuthProtocolException without a redirect URI, which the caller attaches */
    private function validateForUser(Uuid $userId, Client $client, PushedAuthorizationRequest $pushedRequest): ValidatedAuthorizationRequest
    {
        // A personal access client acts for its owner alone, who still decides on each request.
        if ($client->isPersonalAccessClient() && !$client->isOwnedBy($userId)) {
            throw OAuthProtocolException::unauthorizedClient('A personal access client serves only the user who created it.');
        }

        $user = $this->userRepository->findByUuid($userId);
        if ($user === null || $user->isDisabled()) {
            throw OAuthProtocolException::accessDenied('The user account is unavailable.');
        }

        return new ValidatedAuthorizationRequest($client, $user, $pushedRequest);
    }

    /**
     * Registered URIs match exactly. A loopback URI (RFC 8252 section 7.3) also matches on any port,
     * since native apps listen on an ephemeral one, when a browser would send it to the same scheme,
     * host, path and query; a backslash, userinfo or a fragment never matches. Omitting the URI is allowed only when the client
     * registered exactly one.
     *
     * @throws OAuthProtocolException invalid_request
     */
    private function resolveRedirectUri(Client $client, ?string $requested): string
    {
        $registered = array_values(array_filter($client->getRedirectUris(), static fn (string $uri): bool => $uri !== ''));

        if ($requested === null || $requested === '') {
            if (count($registered) === 1) {
                return $registered[0];
            }

            throw OAuthProtocolException::invalidRequest('The redirect_uri parameter is required.');
        }

        foreach ($registered as $uri) {
            if ($uri === $requested || self::isSameLoopbackEndpoint($uri, $requested)) {
                return $requested;
            }
        }

        throw OAuthProtocolException::invalidRequest('The redirect_uri is not registered for this client.');
    }

    private static function isSameLoopbackEndpoint(string $registered, string $requested): bool
    {
        $registeredUri = RedirectUri::parse($registered);
        $requestedUri = RedirectUri::parse($requested);

        return $registeredUri !== null && $requestedUri !== null && $registeredUri->isSameLoopbackEndpointAs($requestedUri);
    }
}
