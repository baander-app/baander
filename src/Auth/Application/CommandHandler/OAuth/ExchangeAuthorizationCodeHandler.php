<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\ExchangeAuthorizationCodeCommand;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Service\OAuthClientAuthenticator;
use App\Auth\Application\Service\TokenPairIssuer;
use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Repository\OAuth\AuthCodeRepositoryInterface;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The authorization code grant (RFC 6749 section 4.1.3) with mandatory PKCE (RFC 7636).
 *
 * The code must belong to the authenticated client, be unexpired and unused, have
 * been issued for the same redirect URI, and match the code verifier. It is
 * redeemed in the transaction that stores the new DPoP-bound token pair, so
 * concurrent redemptions yield one pair.
 */
final readonly class ExchangeAuthorizationCodeHandler
{
    public function __construct(
        private OAuthClientAuthenticator $clientAuthenticator,
        private AuthCodeRepositoryInterface $authCodeRepository,
        private TokenPairIssuer $tokenPairIssuer,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(ExchangeAuthorizationCodeCommand $command): TokenResponseDTO
    {
        $client = $this->clientAuthenticator->authenticate($command->clientId, $command->clientSecret);

        if ($command->code === null || $command->code === '') {
            throw OAuthProtocolException::invalidRequest('The code parameter is required.');
        }

        $authCode = $this->findRedeemableCode($command->code, $client);
        $this->checkRedirectUri($authCode, $client, $command->redirectUri);

        if (!$authCode->matchesCodeVerifier($command->codeVerifier)) {
            throw OAuthProtocolException::invalidGrant('The code_verifier does not match the code challenge.');
        }

        $user = $authCode->getUser();
        if ($user->isDisabled()) {
            throw OAuthProtocolException::invalidGrant('The user account is unavailable.');
        }

        return $this->tokenPairIssuer->issue(
            client: $client,
            user: $user,
            requestedScopes: $authCode->getScopeIdentifiers(),
            dpopJkt: $command->dpopJkt,
            clientFingerprint: $command->clientFingerprint,
            userAgent: $command->userAgent,
            ipAddress: $command->ipAddress,
            persistWithTokens: function () use ($authCode): void {
                if (!$this->authCodeRepository->redeem($authCode)) {
                    throw OAuthProtocolException::invalidGrant('The authorization code was already used or has expired.');
                }
            },
        );
    }

    private function findRedeemableCode(string $code, Client $client): AuthCode
    {
        try {
            $authCode = $this->authCodeRepository->findByCodeId(TokenId::fromString($code));
        } catch (InvalidArgumentException) {
            $authCode = null;
        }

        if ($authCode === null || $authCode->isRevoked() || $authCode->isExpired()
            || !$authCode->getClient()->getId()->equals($client->getId())) {
            throw OAuthProtocolException::invalidGrant('The authorization code is invalid, expired or already used.');
        }

        return $authCode;
    }

    /** RFC 6749 section 4.1.3: the token request repeats the redirect URI the code was issued for. */
    private function checkRedirectUri(AuthCode $authCode, Client $client, ?string $redirectUri): void
    {
        if ($redirectUri === null || $redirectUri === '') {
            // The authorization request could omit it only for a client with a single registered URI.
            $registered = array_values(array_filter($client->getRedirectUris(), static fn (string $uri): bool => $uri !== ''));
            if ($registered === [$authCode->getRedirectUri()]) {
                return;
            }

            throw OAuthProtocolException::invalidRequest('The redirect_uri parameter is required.');
        }

        if (!$authCode->isIssuedFor($redirectUri)) {
            throw OAuthProtocolException::invalidGrant('The redirect_uri does not match the authorization request.');
        }
    }
}
