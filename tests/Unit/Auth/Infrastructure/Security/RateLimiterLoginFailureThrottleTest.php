<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security;

use App\Auth\Infrastructure\Security\RateLimiterLoginFailureThrottle;
use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class RateLimiterLoginFailureThrottleTest extends TestCase
{
    private RateLimiterLoginFailureThrottle $throttle;

    protected function setUp(): void
    {
        $this->throttle = new RateLimiterLoginFailureThrottle(new RateLimiterFactory(
            ['id' => 'auth_login_email_failures', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '300 seconds'],
            new InMemoryStorage(),
        ));
    }

    public function testChecksDoNotUseUpTheAllowance(): void
    {
        foreach (range(1, 5) as $ignored) {
            self::assertNull($this->throttle->retryAfter(new Email('alice@baander.app')));
        }
    }

    public function testTheLastAllowedFailureLocksTheAddressAndItsCaseVariants(): void
    {
        foreach (range(1, 2) as $ignored) {
            $this->throttle->recordFailure(new Email('alice@baander.app'));
        }
        self::assertNull($this->throttle->retryAfter(new Email('alice@baander.app')));

        $this->throttle->recordFailure(new Email('Alice@baander.app'));

        $retryAfter = $this->throttle->retryAfter(new Email('ALICE@baander.app'));
        self::assertNotNull($retryAfter);
        self::assertGreaterThan(0, $retryAfter);
        self::assertNull($this->throttle->retryAfter(new Email('bob@baander.app')));
    }
}
