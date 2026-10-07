<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\CreateAuthorizationCodeCommand;
use App\Auth\Application\DTO\AuthorizationCodeDTO;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\ScopeAllowlist;
use App\Auth\Application\Service\OAuthClientAuthenticator;
use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\AuthCodeRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use DateInterval;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Issues an authorization code to a client the signed-in user authorizes (RFC 6749 section 4.1).
 *
 * The client and its redirect URI are checked first; until both are valid an
 * error must not be redirected. After that every error is sent to the redirect
 * URI. PKCE is mandatory for every client and only S256 is accepted
 * (RFC 9700 section 2.1.1); "plain" and a missing method are rejected.
 */
final readonly class CreateAuthorizationCodeHandler
{
    private DateInterval $authCodeTtl;

    public function __construct(
        private OAuthClientAuthenticator $clientAuthenticator,
        private UserRepositoryInterface $userRepository,
        private AuthCodeRepositoryInterface $authCodeRepository,
        private ScopeAllowlist $scopeAllowlist,
        int $authCodeTtl,
    ) {
        $this->authCodeTtl = new DateInterval(sprintf('PT%dS', $authCodeTtl));
    }

    #[AsMessageHandler]
    public function __invoke(CreateAuthorizationCodeCommand $command): AuthorizationCodeDTO
    {
        $client = $this->clientAuthenticator->identify($command->clientId);
        $redirectUri = $this->resolveRedirectUri($client, $command->redirectUri);

        try {
            $code = $this->issue($command, $client, $redirectUri);
        } catch (OAuthProtocolException $exception) {
            throw $exception->redirectTo($redirectUri);
        }

        return new AuthorizationCodeDTO($code->getCodeId()->toString(), $redirectUri);
    }

    private function issue(CreateAuthorizationCodeCommand $command, Client $client, string $redirectUri): AuthCode
    {
        if ($command->responseType !== 'code') {
            throw OAuthProtocolException::unsupportedResponseType();
        }
        if ($client->isDeviceClient()) {
            throw OAuthProtocolException::unauthorizedClient('Device clients use the device authorization grant.');
        }
        if ($command->codeChallenge === null || $command->codeChallenge === '') {
            throw OAuthProtocolException::invalidRequest('PKCE code_challenge is required.');
        }
        if ($command->codeChallengeMethod !== AuthCode::CODE_CHALLENGE_METHOD) {
            throw OAuthProtocolException::invalidRequest('PKCE code_challenge_method must be S256.');
        }
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $command->codeChallenge) !== 1) {
            throw OAuthProtocolException::invalidRequest('The code_challenge is not a base64url SHA-256 digest.');
        }

        $user = $this->userRepository->findByUuid($command->userId);
        if ($user === null || $user->isDisabled()) {
            throw OAuthProtocolException::accessDenied('The user account is unavailable.');
        }

        $scopes = array_map(
            static fn (string $scope): Scope => new Scope($scope),
            array_values(array_unique($this->scopeAllowlist->filter($command->scopes))),
        );

        $code = AuthCode::create(
            $user,
            $client,
            $redirectUri,
            $command->codeChallenge,
            $command->codeChallengeMethod,
            $scopes,
            $this->authCodeTtl,
        );
        $this->authCodeRepository->save($code);

        return $code;
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
