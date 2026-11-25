<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\RateLimiterLoginFailureThrottle;
use App\Shared\Domain\Model\Email;
use App\Tests\Fixtures\RateLimiter\InMemoryRateLimiterStore;
use Baander\RedisRateLimiter\Limiter\RedisRateLimiterFactory;
use PHPUnit\Framework\TestCase;

final class RateLimiterLoginFailureThrottleTest extends TestCase
{
    private const string LIMITER = 'auth_login_email_failures';

    private InMemoryRateLimiterStore $store;
    private RateLimiterLoginFailureThrottle $throttle;

    protected function setUp(): void
    {
        $this->store = new InMemoryRateLimiterStore();
        $this->throttle = new RateLimiterLoginFailureThrottle(new RedisRateLimiterFactory(
            ['id' => self::LIMITER, 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '300 seconds'],
            $this->store,
        ));
    }

    public function testEachReservationTakesAFailureUntilTheAddressAndItsCaseVariantsWait(): void
    {
        foreach (['alice@baander.app', 'Alice@baander.app', 'ALICE@baander.app'] as $address) {
            self::assertNull($this->throttle->reserve(new Email($address)));
        }

        $retryAfter = $this->throttle->reserve(new Email('alice@baander.app'));

        self::assertNotNull($retryAfter);
        self::assertGreaterThan(0, $retryAfter);
        self::assertNull($this->throttle->reserve(new Email('bob@baander.app')));
    }

    public function testARefundGivesTheReservedFailureBack(): void
    {
        $alice = new Email('alice@baander.app');
        foreach (range(1, 3) as $ignored) {
            self::assertNull($this->throttle->reserve($alice));
        }

        $this->throttle->refund($alice);

        self::assertNull($this->throttle->reserve($alice));
        self::assertNotNull($this->throttle->reserve($alice));
    }

    /** The failures the clear removed are not given back a second time. */
    public function testARefundAfterTheLimiterWasClearedLeavesTheCountAtZero(): void
    {
        $alice = new Email('alice@baander.app');
        foreach (range(1, 3) as $ignored) {
            self::assertNull($this->throttle->reserve($alice));
        }
        $this->store->clear(self::LIMITER);

        $this->throttle->refund($alice);

        foreach (range(1, 3) as $ignored) {
            self::assertNull($this->throttle->reserve($alice));
        }
        self::assertNotNull($this->throttle->reserve($alice));
    }
}
