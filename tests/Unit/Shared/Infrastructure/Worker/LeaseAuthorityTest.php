<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\LeaseAuthority;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LeaseAuthorityTest extends TestCase
{
    private const string NAMESPACE = 'lease-policy.baander.app';
    private const string BOOT = '0123456789abcdef0123456789abcdef';

    public function testInitialAcquireIsUnreadyAndSingleFlight(): void
    {
        $authority = $this->authority();
        self::assertFalse($authority->hasAuthority(0));
        self::assertFalse($authority->isRevoked(0));
        self::assertNull($authority->lease(0));
        $request = $authority->request(1);
        self::assertSame(['action' => 'acquire', 'sequence' => 1, 'namespace' => self::NAMESPACE, 'bootId' => self::BOOT, 'epoch' => null, 'ttlSeconds' => 10], $request);
        self::assertNull($authority->request(2));
        self::assertFalse($authority->hasAuthority(2));
        self::assertFalse($authority->isRevoked(2));
        $grant = $this->grant();
        self::assertTrue($authority->accept(1, $grant, 3));
        self::assertSame($grant, $authority->lease(3));
        self::assertTrue($authority->hasAuthority(3));
    }

    public function testDeadlineStartsAtRequestNotDelayedReplyArrival(): void
    {
        $authority = $this->authority();
        $authority->request(1);
        self::assertTrue($authority->accept(1, $this->grant(), 8));
        self::assertTrue($authority->hasAuthority(10.899));
        self::assertFalse($authority->hasAuthority(10.9));
        self::assertTrue($authority->isRevoked(10.9));
        self::assertNull($authority->lease(10.9));
        self::assertNull($authority->request(100));
    }

    public function testRenewalStartsAtMarginAndUsesNewRequestStart(): void
    {
        $authority = $this->authority();
        $authority->request(0);
        $grant = $this->grant();
        self::assertTrue($authority->accept(1, $grant, 1));
        self::assertNull($authority->request(7.899));
        self::assertSame(['action' => 'renew', 'sequence' => 2, 'namespace' => self::NAMESPACE, 'bootId' => self::BOOT, 'epoch' => 7, 'ttlSeconds' => 10], $authority->request(7.9));
        self::assertTrue($authority->hasAuthority(9));
        self::assertTrue($authority->accept(2, $grant, 9));
        self::assertTrue($authority->hasAuthority(17.799));
        self::assertFalse($authority->hasAuthority(17.8));
    }

    public function testLateRenewalCannotResurrectExpiredOldAuthority(): void
    {
        $authority = $this->authority();
        $authority->request(0);
        $authority->accept(1, $this->grant(), 1);
        $authority->request(8);
        self::assertFalse($authority->accept(2, $this->grant(), 10));
        self::assertTrue($authority->isRevoked(10));
        self::assertFalse($authority->accept(2, $this->grant(), 10.9));
        self::assertFalse($authority->hasAuthority(10.9));
    }

    public function testAcquireAtDeadlineIsRejectedWithoutAnyAuthority(): void
    {
        $authority = $this->authority();
        $authority->request(1);
        self::assertFalse($authority->accept(1, $this->grant(), 10.9));
        self::assertTrue($authority->isRevoked(10.9));
        self::assertNull($authority->request(12));
    }

    public function testAcquireTimeoutAndExplicitRevocationAreIrreversible(): void
    {
        $authority = $this->authority();
        $authority->request(0);
        self::assertTrue($authority->isRevoked(10));
        self::assertFalse($authority->accept(1, $this->grant(), 10));
        $fresh = $this->authority();
        $fresh->revoke(0);
        $fresh->revoke(1);
        self::assertTrue($fresh->isRevoked(1));
        self::assertNull($fresh->request(1));
    }

    public function testOldFutureAndDuplicateRepliesCannotExtendOrReplaceAuthority(): void
    {
        $authority = $this->authority();
        $authority->request(0);
        self::assertFalse($authority->accept(2, $this->grant(), 1));
        self::assertFalse($authority->hasAuthority(1));
        self::assertTrue($authority->accept(1, $this->grant(), 2));
        self::assertFalse($authority->accept(1, $this->grant(99), 3));
        self::assertSame(7, $authority->lease(3)?->epoch);
        $authority->request(8);
        self::assertFalse($authority->accept(1, null, 8.1));
        self::assertTrue($authority->hasAuthority(8.1));
        self::assertTrue($authority->accept(2, $this->grant(), 9));
        self::assertFalse($authority->accept(2, $this->grant(), 17.7));
        self::assertFalse($authority->hasAuthority(17.9));
    }

    #[DataProvider('invalidReplies')]
    public function testMatchingFailedOrMismatchedReplyPermanentlyRevokes(?DeploymentLease $grant): void
    {
        $authority = $this->authority();
        $authority->request(0);
        self::assertFalse($authority->accept(1, $grant, 1));
        self::assertTrue($authority->isRevoked(1));
        self::assertFalse($authority->accept(1, $this->grant(), 2));
        self::assertNull($authority->request(2));
    }

    public function testRenewedGrantCannotChangeEpoch(): void
    {
        $authority = $this->authority();
        $authority->request(0);
        $authority->accept(1, $this->grant(), 1);
        $authority->request(8);
        self::assertFalse($authority->accept(2, $this->grant(8), 9));
        self::assertTrue($authority->isRevoked(9));
        self::assertNull($authority->lease(9));
    }

    /** @return iterable<string,array{?DeploymentLease}> */
    public static function invalidReplies(): iterable
    {
        yield 'helper failure' => [null];
        yield 'other namespace' => [new DeploymentLease('other.baander.app', self::BOOT, 7)];
        yield 'other boot' => [new DeploymentLease(self::NAMESPACE, str_repeat('f', 32), 7)];
    }

    #[DataProvider('invalidConfigurations')]
    public function testRejectsInvalidBoundedTimingConfiguration(int $ttl, float $margin): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LeaseAuthority(self::NAMESPACE, self::BOOT, $ttl, $margin);
    }

    /** @return iterable<string,array{int,float}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'zero TTL' => [0, 0.1];
        yield 'unbounded TTL' => [3601, 1];
        yield 'zero margin' => [10, 0];
        yield 'negative margin' => [10, -1];
        yield 'margin equals TTL' => [10, 10];
        yield 'margin exceeds TTL' => [10, 11];
        yield 'infinite margin' => [10, INF];
        yield 'NaN margin' => [10, NAN];
    }

    #[DataProvider('invalidSafetyMargins')]
    public function testSafetyMarginMustFitAlongsideRenewalMargin(float $margin): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LeaseAuthority(self::NAMESPACE, self::BOOT, 10, 2, $margin);
    }

    /** @return iterable<string,array{float}> */
    public static function invalidSafetyMargins(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'infinite' => [INF];
        yield 'NaN' => [NAN];
        yield 'whole TTL' => [10];
        yield 'no renewal room' => [8];
        yield 'too little renewal room' => [9];
    }

    #[DataProvider('invalidTimes')]
    public function testInvalidOrUnrepresentableRequestTimeFailsClosed(float $now): void
    {
        $authority = $this->authority();
        $this->expectException(InvalidArgumentException::class);
        $authority->request($now);
    }

    /** @return iterable<string,array{float}> */
    public static function invalidTimes(): iterable
    {
        yield 'negative' => [-1];
        yield 'infinite' => [INF];
        yield 'NaN' => [NAN];
        yield 'deadline cannot advance' => [PHP_FLOAT_MAX];
    }

    #[DataProvider('clockMethods')]
    public function testEveryEntryPointRejectsClockRegressionAndRevokes(string $method): void
    {
        $authority = $this->authority();
        $authority->request(2);
        try {
            if ($method === 'accept') {
                $authority->accept(1, $this->grant(), 1);
            } else {
                $authority->$method(1);
            }
            self::fail('A regressing clock must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue($authority->isRevoked(2));
        }
    }

    /** @return iterable<string,array{string}> */
    public static function clockMethods(): iterable
    {
        foreach (['request', 'accept', 'hasAuthority', 'isRevoked', 'lease', 'revoke'] as $method) {
            yield $method => [$method];
        }
    }

    private function authority(): LeaseAuthority
    {
        return new LeaseAuthority(self::NAMESPACE, self::BOOT, 10, 2);
    }

    private function grant(int $epoch = 7): DeploymentLease
    {
        return new DeploymentLease(self::NAMESPACE, self::BOOT, $epoch);
    }
}
