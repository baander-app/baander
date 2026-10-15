<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\RateLimiter;

use App\Shared\Application\Exception\RateLimiterUnavailableException;
use App\Shared\Infrastructure\RateLimiter\RateLimiterSnapshot;
use App\Shared\Infrastructure\RateLimiter\RateLimiterStateCodec;
use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterFactory;
use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\Policy\Window;

final class RateLimiterFailureSemanticsTest extends TestCase
{
    public function testStorageFailureCannotGrantAFreshBudget(): void
    {
        $store = $this->createMock(RedisRateLimiterStore::class);
        $store->expects($this->once())->method('read')->willThrowException(new RateLimiterUnavailableException());
        $store->expects($this->never())->method('commit');
        $this->expectException(RateLimiterUnavailableException::class);
        (new RedisRateLimiterFactory($this->config(), $store))->create('192.0.2.20')->consume();
    }

    public function testLosingAComparisonRefetchesTheBudgetInsteadOfAcceptingTwice(): void
    {
        $config = $this->config();
        $reads = 0;
        $store = $this->createMock(RedisRateLimiterStore::class);
        $store->expects($this->exactly(2))->method('read')->willReturnCallback(
            static function (string $name, string $subject) use ($config, &$reads): RateLimiterSnapshot {
                $payload = '';
                if (++$reads === 2) {
                    $window = new Window($name . '-' . $subject, 60, 1);
                    $window->add(1);
                    $payload = (new RateLimiterStateCodec($config, $window->getId()))->encode($window);
                }

                return new RateLimiterSnapshot($name, $subject, str_repeat('a', 32), str_repeat('b', 32), $payload, 1);
            },
        );
        $store->expects($this->once())->method('commit')->willReturn(false);
        self::assertFalse((new RedisRateLimiterFactory($config, $store))->create('192.0.2.20')->consume()->isAccepted());
    }

    public function testAnAmbiguousWriteIsNeverReplayed(): void
    {
        $store = $this->createMock(RedisRateLimiterStore::class);
        $store->expects($this->once())->method('read')->willReturn($this->snapshot());
        $store->expects($this->once())->method('commit')->willThrowException(new RateLimiterUnavailableException());
        $this->expectException(RateLimiterUnavailableException::class);
        (new RedisRateLimiterFactory($this->config(), $store))->create('192.0.2.20')->consume();
    }

    public function testComparisonConflictsStopAfterEightAttempts(): void
    {
        $store = $this->createMock(RedisRateLimiterStore::class);
        $store->expects($this->exactly(8))->method('read')->willReturn($this->snapshot());
        $store->expects($this->exactly(8))->method('commit')->willReturn(false);
        $this->expectException(RateLimiterUnavailableException::class);
        (new RedisRateLimiterFactory($this->config(), $store))->create('192.0.2.20')->consume();
    }

    public function testSlowReadCannotReturnAnAcceptedInspectionAfterTheDeadline(): void
    {
        $snapshot = $this->snapshot();
        $store = $this->createMock(RedisRateLimiterStore::class);
        $store->expects($this->once())->method('read')->willReturnCallback(static function () use ($snapshot): RateLimiterSnapshot {
            usleep(2_010_000);

            return $snapshot;
        });
        $store->expects($this->never())->method('commit');
        $this->expectException(RateLimiterUnavailableException::class);
        (new RedisRateLimiterFactory($this->config(), $store))->create('subject')->consume(0);
    }

    private function snapshot(): RateLimiterSnapshot
    {
        return new RateLimiterSnapshot('test', 'subject', str_repeat('a', 32), str_repeat('b', 32), '', 1);
    }

    /** @return array{id: string, policy: string, limit: int, interval: string} */
    private function config(): array
    {
        return ['id' => 'test', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '60 seconds'];
    }
}
