<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\ExchangeAuthorizationCodeCommand;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Service\OAuthClientAuthenticator;
use App\Auth\Application\Service\TokenChainRevoker;
use App\Auth\Application\Service\TokenPairIssuer;
use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Repository\OAuth\AuthCodeRepositoryInterface;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The authorization code grant (RFC 6749 section 4.1.3) with mandatory PKCE (RFC 7636).
 *
 * The code must belong to the authenticated client, be unexpired and unused, have
 * been issued for the same redirect URI, match the code verifier, and be redeemed
 * with a DPoP proof from the key it is bound to (RFC 9449 section 10). It is
 * redeemed in the transaction that stores the new DPoP-bound token pair, so
 * concurrent redemptions yield one pair. The code remembers the chain that pair
 * started, and the client presenting the redeemed code again ends that chain
 * (RFC 6749 section 4.1.2): a code presented twice was probably stolen.
 */
final readonly class ExchangeAuthorizationCodeHandler
{
    public function __construct(
        private OAuthClientAuthenticator $clientAuthenticator,
        private AuthCodeRepositoryInterface $authCodeRepository,
        private TokenPairIssuer $tokenPairIssuer,
        private TokenChainRevoker $chainRevoker,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(ExchangeAuthorizationCodeCommand $command): TokenResponseDTO
    {
        $client = $this->clientAuthenticator->authenticate($command->credentials);

        if ($command->code === null || $command->code === '') {
            throw OAuthProtocolException::invalidRequest('The code parameter is required.');
        }

        $authCode = $this->findRedeemableCode($command->code, $client);
        $this->checkRedirectUri($authCode, $client, $command->redirectUri);

        if (!$authCode->matchesCodeVerifier($command->codeVerifier)) {
            throw OAuthProtocolException::invalidGrant('The code_verifier does not match the code challenge.');
        }
        if (!$authCode->isBoundTo($command->dpopJkt)) {
            throw OAuthProtocolException::invalidGrant('The DPoP proof is not signed by the key the authorization code is bound to.');
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
            persistWithTokens: function (ChainId $chainId) use ($authCode): void {
                if (!$this->authCodeRepository->redeem($authCode, $chainId)) {
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

        if ($authCode === null || !$authCode->getClient()->getId()->equals($client->getId())) {
            throw self::unusableCode();
        }
        if ($authCode->isRevoked()) {
            $this->endChainOfReplayedCode($authCode);

            throw self::unusableCode();
        }
        if ($authCode->isExpired()) {
            throw self::unusableCode();
        }

        return $authCode;
    }

    /**
     * Revokes, and commits, the chain a redeemed code started before the replay is refused.
     *
     * Only a code its own client presents again counts as replayed; the PKCE and DPoP checks
     * do not run first, because whoever replays a stolen code has neither.
     */
    private function endChainOfReplayedCode(AuthCode $authCode): void
    {
        $chainId = $authCode->getIssuedChainId();
        if ($chainId !== null) {
            $this->chainRevoker->revoke($chainId);
        }
    }

    private static function unusableCode(): OAuthProtocolException
    {
        return OAuthProtocolException::invalidGrant('The authorization code is invalid, expired or already used.');
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
