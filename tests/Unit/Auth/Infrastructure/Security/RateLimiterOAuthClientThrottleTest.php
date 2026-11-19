<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Application\Exception\OAuthClientRateLimitedException;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Infrastructure\Security\RateLimiterOAuthClientThrottle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class RateLimiterOAuthClientThrottleTest extends TestCase
{
    private InMemoryStorage $storage;
    private RateLimiterOAuthClientThrottle $throttle;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->throttle = new RateLimiterOAuthClientThrottle(
            oauthTokenClientLimiter: $this->factory('oauth_token_client'),
            oauthParClientLimiter: $this->factory('oauth_par_client'),
            logger: new NullLogger(),
            environment: 'test',
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function sameNetwork(): iterable
    {
        yield 'IPv4 /24' => ['198.51.100.7', '198.51.100.250'];
        yield 'IPv6 /64' => ['2001:db8:1:2::1', '2001:db8:1:2:ffff:ffff:ffff:ffff'];
    }

    #[DataProvider('sameNetwork')]
    public function testAPublicClientSharesOneBucketWithinACallerNetwork(string $first, string $second): void
    {
        $client = Client::registerPublic('Player', ['https://player.baander.app/callback']);
        $this->throttle->chargePushedRequest($client, $first);
        $this->throttle->chargePushedRequest($client, $first);

        $this->expectException(OAuthClientRateLimitedException::class);

        $this->throttle->chargePushedRequest($client, $second);
    }

    /** @return iterable<string, array{string, string}> */
    public static function otherNetwork(): iterable
    {
        yield 'IPv4 next /24' => ['198.51.100.7', '198.51.101.7'];
        yield 'IPv6 next /64' => ['2001:db8:1:2::1', '2001:db8:1:3::1'];
    }

    #[DataProvider('otherNetwork')]
    public function testAPublicClientDrainedFromOneNetworkStillServesAnother(string $drained, string $other): void
    {
        $client = Client::registerPublic('Player', ['https://player.baander.app/callback']);
        $this->drain(fn () => $this->throttle->chargePushedRequest($client, $drained));

        $this->throttle->chargePushedRequest($client, $other);
        $this->addToAssertionCount(1);
    }

    public function testAConfidentialClientSharesOneBucketAcrossNetworks(): void
    {
        $client = Client::registerConfidential('Server', ['https://server.baander.app/callback'], ClientSecret::fromString('server-secret'));
        $this->throttle->chargeTokenRequest($client, '198.51.100.7');
        $this->throttle->chargeTokenRequest($client, '203.0.113.9');

        try {
            $this->throttle->chargeTokenRequest($client, '2001:db8::1');
            self::fail('The third request must exceed the allowance of two.');
        } catch (OAuthClientRateLimitedException $exception) {
            self::assertGreaterThanOrEqual(1, $exception->retryAfterSeconds);
        }
    }

    public function testTokenAndPushedRequestsHaveSeparateBuckets(): void
    {
        $client = Client::registerConfidential('Server', ['https://server.baander.app/callback'], ClientSecret::fromString('server-secret'));
        $this->drain(fn () => $this->throttle->chargeTokenRequest($client, '198.51.100.7'));

        $this->throttle->chargePushedRequest($client, '198.51.100.7');
        $this->addToAssertionCount(1);
    }

    private function drain(callable $charge): void
    {
        $charge();
        $charge();
        try {
            $charge();
            self::fail('The third request must exceed the allowance of two.');
        } catch (OAuthClientRateLimitedException) {
        }
    }

    private function factory(string $id): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => $id, 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute'], $this->storage);
    }
}
