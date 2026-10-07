<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\DTO\ValidatedAuthorizationRequest;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\ScopeAllowlist;
use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Uuid;

/**
 * Validates an authorization request (RFC 6749 section 4.1.1) for the signed-in user.
 *
 * The client and its redirect URI are checked first; until both are valid an
 * error must not be redirected (RFC 6749 section 4.1.2.1). After that every
 * error carries the validated redirect URI. PKCE is mandatory for every client
 * and only S256 is accepted (RFC 9700 section 2.1.1); "plain" and a missing
 * method are rejected.
 */
final readonly class AuthorizationRequestValidator
{
    public function __construct(
        private OAuthClientAuthenticator $clientAuthenticator,
        private UserRepositoryInterface $userRepository,
        private ScopeAllowlist $scopeAllowlist,
    ) {
    }

    /**
     * @param string[] $scopes
     *
     * @throws OAuthProtocolException
     */
    public function validate(
        Uuid $userId,
        string $responseType,
        string $clientId,
        ?string $redirectUri,
        ?string $codeChallenge,
        ?string $codeChallengeMethod,
        array $scopes,
    ): ValidatedAuthorizationRequest {
        [$client, $resolvedRedirectUri] = $this->validateClient($clientId, $redirectUri);

        try {
            return $this->validateParameters($userId, $client, $resolvedRedirectUri, $responseType, $codeChallenge, $codeChallengeMethod, $scopes);
        } catch (OAuthProtocolException $exception) {
            throw $exception->redirectTo($resolvedRedirectUri);
        }
    }

    /**
     * Resolves the client and its redirect URI; errors here are never redirected.
     *
     * @return array{Client, string}
     *
     * @throws OAuthProtocolException invalid_client or invalid_request, without a redirect URI
     */
    public function validateClient(string $clientId, ?string $redirectUri): array
    {
        $client = $this->clientAuthenticator->identify($clientId);

        return [$client, $this->resolveRedirectUri($client, $redirectUri)];
    }

    /** @param string[] $scopes */
    private function validateParameters(
        Uuid $userId,
        Client $client,
        string $redirectUri,
        string $responseType,
        ?string $codeChallenge,
        ?string $codeChallengeMethod,
        array $scopes,
    ): ValidatedAuthorizationRequest {
        if ($responseType !== 'code') {
            throw OAuthProtocolException::unsupportedResponseType();
        }
        if ($client->isDeviceClient()) {
            throw OAuthProtocolException::unauthorizedClient('Device clients use the device authorization grant.');
        }
        // A personal access client acts for its owner alone, who needs no consent screen.
        if ($client->isPersonalAccessClient() && !$client->isOwnedBy($userId)) {
            throw OAuthProtocolException::unauthorizedClient('A personal access client serves only the user who created it.');
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

        $user = $this->userRepository->findByUuid($userId);
        if ($user === null || $user->isDisabled()) {
            throw OAuthProtocolException::accessDenied('The user account is unavailable.');
        }

        $grantedScopes = array_map(
            static fn (string $scope): Scope => new Scope($scope),
            array_values(array_unique($this->scopeAllowlist->filter($scopes))),
        );

        return new ValidatedAuthorizationRequest($client, $user, $redirectUri, $codeChallenge, $codeChallengeMethod, $grantedScopes);
    }

    /**
     * Registered URIs match exactly. A loopback URI (RFC 8252 section 7.3) also matches on any port,
     * since native apps listen on an ephemeral one. Omitting the URI is allowed only when the client
     * registered exactly one.
     *
     * @throws OAuthProtocolException invalid_request, never redirected
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
        $a = parse_url($registered);
        $b = parse_url($requested);
        if (!is_array($a) || !is_array($b)) {
            return false;
        }

        $loopback = ['localhost', '127.0.0.1', '[::1]'];

        return ($a['scheme'] ?? null) === 'http'
            && ($b['scheme'] ?? null) === 'http'
            && in_array($a['host'] ?? null, $loopback, true)
            && $a['host'] === ($b['host'] ?? null)
            && ($a['path'] ?? '') === ($b['path'] ?? '')
            && !isset($b['user'], $b['pass'], $b['fragment'])
            && ($a['query'] ?? null) === ($b['query'] ?? null);
    }
}
