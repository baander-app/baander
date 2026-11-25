<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security;

use App\Auth\Application\Exception\OAuthClientRateLimitedException;
use App\Auth\Application\Port\OAuthClientThrottleInterface;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientAuthMethod;
use App\Shared\Infrastructure\Http\RateLimitSubject;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Backs the per-client limits with the `oauth_token_client` and `oauth_par_client` limiters.
 *
 * A client with a secret or keys is keyed by its public ID. A public client is keyed by
 * its public ID and the caller's network, the /24 of an IPv4 address or the /64 of an
 * IPv6 address, so callers elsewhere who name the client cannot exhaust its allowance.
 */
final readonly class RateLimiterOAuthClientThrottle implements OAuthClientThrottleInterface
{
    public function __construct(
        private RateLimiterFactoryInterface $oauthTokenClientLimiter,
        private RateLimiterFactoryInterface $oauthParClientLimiter,
        private LoggerInterface $logger,
        private string $environment,
    ) {
    }

    public function chargeTokenRequest(Client $client, ?string $ipAddress): void
    {
        $this->charge($this->oauthTokenClientLimiter, 'oauth_token_client', $client, $ipAddress);
    }

    public function chargePushedRequest(Client $client, ?string $ipAddress): void
    {
        $this->charge($this->oauthParClientLimiter, 'oauth_par_client', $client, $ipAddress);
    }

    private function charge(RateLimiterFactoryInterface $factory, string $limiterName, Client $client, ?string $ipAddress): void
    {
        // As the per-IP limits, the per-client limits are off in the dev environment.
        if ($this->environment === 'dev') {
            return;
        }

        $result = $factory->create(self::key($client, $ipAddress))->consume(1);
        if ($result->isAccepted()) {
            return;
        }

        $seconds = max(1, (int) ceil($result->getRetryAfter()->getTimestamp() - time()));
        $this->logger->warning('OAuth client rate limit exceeded', [
            'limiter' => $limiterName,
            'client_id' => $client->getPublicId()->toString(),
            'ip' => $ipAddress,
            'retry_after' => $seconds,
        ]);

        throw new OAuthClientRateLimitedException($seconds);
    }

    private static function key(Client $client, ?string $ipAddress): string
    {
        $clientId = $client->getPublicId()->toString();

        return $client->getAuthMethod() === ClientAuthMethod::None
            ? $clientId . '|' . RateLimitSubject::ofNetwork($ipAddress)
            : $clientId;
    }
}
