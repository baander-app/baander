<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Worker\RestartPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class RestartPolicyTest extends TestCase
{
    public function testBackoffDoublesAndStopsGrowingAtTheConfiguredCap(): void
    {
        $policy = new RestartPolicy(maxRestarts: 10, initialDelaySeconds: 0.25, maxDelaySeconds: 1.0, jitterRatio: 0.0);

        self::assertSame([0.25, 0.5, 1.0, 1.0], array_map($policy->nextDelay(...), [100.0, 100.25, 100.75, 101.75]));
        self::assertFalse($policy->isExhausted());
    }

    public function testRestartBudgetAllowsItsLastAttemptThenExhaustsPermanently(): void
    {
        $policy = new RestartPolicy(maxRestarts: 2, jitterRatio: 0.0);

        self::assertNotNull($policy->nextDelay(10.0));
        self::assertNotNull($policy->nextDelay(11.0));
        self::assertFalse($policy->isExhausted());
        self::assertNull($policy->nextDelay(12.0));
        self::assertTrue($policy->isExhausted());
        self::assertNull($policy->nextDelay(1000.0));
        self::assertTrue($policy->isExhausted());
    }

    public function testOldRestartsExpireAtTheExactWindowBoundary(): void
    {
        $policy = new RestartPolicy(maxRestarts: 2, restartWindowSeconds: 10.0, initialDelaySeconds: 1.0, jitterRatio: 0.0);

        self::assertSame(1.0, $policy->nextDelay(100.0));
        self::assertSame(2.0, $policy->nextDelay(101.0));
        self::assertSame(2.0, $policy->nextDelay(110.0));
        self::assertSame(2.0, $policy->nextDelay(111.0));
        self::assertFalse($policy->isExhausted());
        self::assertSame(1.0, $policy->nextDelay(121.0));
    }

    public function testOneInstantInsideTheWindowStillCountsAgainstTheBudget(): void
    {
        $policy = new RestartPolicy(maxRestarts: 1, restartWindowSeconds: 10.0, jitterRatio: 0.0);
        self::assertNotNull($policy->nextDelay(100.0));
        self::assertNull($policy->nextDelay(109.999));
        self::assertTrue($policy->isExhausted());
    }

    public function testInjectedJitterStaysBoundedAndCannotExceedTheDelayCap(): void
    {
        $samples = [0.0, 0.5, 1.0];
        $policy = new RestartPolicy(
            maxRestarts: 3,
            initialDelaySeconds: 1.0,
            maxDelaySeconds: 2.0,
            jitterRatio: 0.25,
            random: static function () use (&$samples): float { return array_shift($samples); },
        );

        self::assertSame(0.75, $policy->nextDelay(0.0));
        self::assertSame(2.0, $policy->nextDelay(1.0));
        self::assertSame(2.0, $policy->nextDelay(2.0));
        self::assertSame([], $samples);
        self::assertNull($policy->nextDelay(3.0));
    }

    public function testRepeatedFailuresAtTheSameMonotonicInstantConsumeTheBudget(): void
    {
        $policy = new RestartPolicy(maxRestarts: 1, jitterRatio: 0.0);
        self::assertNotNull($policy->nextDelay(100.0));
        self::assertNull($policy->nextDelay(100.0));
    }

    public function testTimeMovingBackwardsIsRejectedWithoutConsumingAnotherRestart(): void
    {
        $policy = new RestartPolicy(maxRestarts: 2, jitterRatio: 0.0);
        self::assertSame(0.25, $policy->nextDelay(100.0));
        try {
            $policy->nextDelay(99.0);
            self::fail('The caller must supply a monotonic clock.');
        } catch (InvalidArgumentException) {
            self::assertFalse($policy->isExhausted());
        }
        self::assertSame(0.5, $policy->nextDelay(101.0));
    }

    #[DataProvider('invalidTimes')]
    public function testNonFiniteOrNegativeMonotonicTimeIsRejected(float $now): void
    {
        $policy = new RestartPolicy();
        $this->expectException(InvalidArgumentException::class);
        $policy->nextDelay($now);
    }

    /** @return iterable<string, array{float}> */
    public static function invalidTimes(): iterable
    {
        yield 'negative' => [-1.0];
        yield 'infinite' => [INF];
        yield 'not a number' => [NAN];
    }

    /** @param array{maxRestarts?: int, restartWindowSeconds?: float, initialDelaySeconds?: float, maxDelaySeconds?: float, jitterRatio?: float} $configuration */
    #[DataProvider('invalidConfiguration')]
    public function testInvalidConfigurationIsRejected(array $configuration): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RestartPolicy(...$configuration);
    }

    /** @return iterable<string, array{array{maxRestarts?: int, restartWindowSeconds?: float, initialDelaySeconds?: float, maxDelaySeconds?: float, jitterRatio?: float}}> */
    public static function invalidConfiguration(): iterable
    {
        yield 'no restart budget' => [['maxRestarts' => 0]];
        yield 'negative restart budget' => [['maxRestarts' => -1]];
        yield 'zero window' => [['restartWindowSeconds' => 0.0]];
        yield 'infinite window' => [['restartWindowSeconds' => INF]];
        yield 'negative initial delay' => [['initialDelaySeconds' => -1.0]];
        yield 'zero initial delay' => [['initialDelaySeconds' => 0.0]];
        yield 'not a number initial delay' => [['initialDelaySeconds' => NAN]];
        yield 'cap below initial delay' => [['initialDelaySeconds' => 2.0, 'maxDelaySeconds' => 1.0]];
        yield 'infinite cap' => [['maxDelaySeconds' => INF]];
        yield 'negative jitter' => [['jitterRatio' => -0.1]];
        yield 'jitter permits zero wait' => [['jitterRatio' => 1.0]];
        yield 'not a number jitter' => [['jitterRatio' => NAN]];
    }

    #[DataProvider('invalidRandomSamples')]
    public function testInvalidRandomSampleDoesNotSpendTheRestartBudget(float $sample): void
    {
        $samples = [$sample, 0.5];
        $policy = new RestartPolicy(maxRestarts: 1, random: static function () use (&$samples): float { return array_shift($samples); });
        try {
            $policy->nextDelay(100.0);
            self::fail('An invalid random sample must be rejected.');
        } catch (UnexpectedValueException) {
            self::assertFalse($policy->isExhausted());
        }
        self::assertSame(0.25, $policy->nextDelay(101.0));
    }

    /** @return iterable<string, array{float}> */
    public static function invalidRandomSamples(): iterable
    {
        yield 'below range' => [-0.1];
        yield 'above range' => [1.1];
        yield 'infinite' => [INF];
        yield 'not a number' => [NAN];
    }
}
