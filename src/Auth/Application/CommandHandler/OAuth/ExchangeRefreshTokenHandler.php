<?php

declare(strict_types=1);

namespace App\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\ExchangeRefreshTokenCommand;
use App\Auth\Application\Command\OAuth\RefreshTokenCommand;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Exception\RefreshTokenRejectedException;
use App\Auth\Application\Service\OAuthClientAuthenticator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The refresh token grant at the token endpoint (RFC 6749 section 6).
 *
 * After authenticating the client it rotates the pair through the same
 * RefreshTokenHandler as first-party refresh: the proof key must match, the
 * fingerprint binding carries forward, and a replayed token revokes its chain.
 * The token must have been issued to the authenticated client. Only a rejection of
 * the token is invalid_grant; a server fault propagates, so the client keeps a token
 * that may still be good.
 */
final readonly class ExchangeRefreshTokenHandler
{
    public function __construct(
        private OAuthClientAuthenticator $clientAuthenticator,
        private RefreshTokenHandler $refreshTokenHandler,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(ExchangeRefreshTokenCommand $command): TokenResponseDTO
    {
        $client = $this->clientAuthenticator->authenticate($command->clientId, $command->clientSecret);

        if ($command->refreshToken === null || $command->refreshToken === '') {
            throw OAuthProtocolException::invalidRequest('The refresh_token parameter is required.');
        }

        try {
            return ($this->refreshTokenHandler)(new RefreshTokenCommand(
                refreshTokenId: $command->refreshToken,
                ipAddress: $command->ipAddress,
                userAgent: $command->userAgent,
                clientFingerprint: $command->clientFingerprint,
                dpopJkt: $command->dpopJkt,
                clientId: $client->getId(),
            ));
        } catch (RefreshTokenRejectedException) {
            throw self::invalidGrant();
        }
    }

    /** The reason (unknown, expired, revoked, replayed, other key, other client) stays internal. */
    private static function invalidGrant(): OAuthProtocolException
    {
        return OAuthProtocolException::invalidGrant('The refresh token is invalid, expired, revoked or bound to another key or client.');
    }
}
