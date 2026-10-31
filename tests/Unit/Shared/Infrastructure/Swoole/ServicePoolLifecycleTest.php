<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use Closure;
use Error;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Resetter;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\BaseServicePool;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePool;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\StabilityChecker;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Component\Locking\Mutex;
use Throwable;

final class ServicePoolLifecycleTest extends TestCase
{
    public function testLaterResetterAccessesOriginalAssignmentOfEarlierPool(): void
    {
        $first = $this->pool();
        $original = $first->get();
        $observed = null;
        $second = $this->pool(function () use ($first, &$observed): void {
            $observed = $first->get();
        });
        $second->get();
        $container = new ServicePoolContainer([0 => [$first, $second]]);

        $container->releaseFromCoroutine($this->cid());

        self::assertSame($original, $observed);
        self::assertSame(0, $first->getAssignedCount());
        self::assertSame(1, $first->getFreeCount());
        self::assertSame(0, $second->getAssignedCount());
        self::assertSame(1, $second->getFreeCount());
    }

    public function testResetterCanAccessItsOwnAssignmentWithoutAllocatingAnother(): void
    {
        $pool = null;
        $observed = null;
        $pool = $this->pool(function () use (&$pool, &$observed): void {
            $observed = $pool->get();
        });
        $original = $pool->get();

        $pool->releaseFromCoroutine($this->cid());

        self::assertSame($original, $observed);
        self::assertSame(0, $pool->getAssignedCount());
        self::assertSame(1, $pool->getFreeCount());
    }

    #[DataProvider('cleanupFailures')]
    public function testFailedResetDiscardsInstanceAndContinuesLaterPools(Throwable $failure): void
    {
        $failed = $this->pool(static function () use ($failure): void {
            throw $failure;
        });
        $original = $failed->get();
        $later = $this->pool();
        $later->get();
        $container = new ServicePoolContainer([0 => [$failed, $later]]);

        $log = $this->captureErrors(fn() => $container->releaseFromCoroutine($this->cid()));
        self::assertStringContainsString($failure::class . ': reset failed', $log);

        self::assertSame(0, $failed->getAssignedCount());
        self::assertSame(0, $failed->getFreeCount());
        self::assertSame(0, $later->getAssignedCount());
        self::assertSame(1, $later->getFreeCount());
        self::assertNotSame($original, $failed->get());
        $this->captureErrors(fn() => $failed->releaseFromCoroutine($this->cid()));
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function cleanupFailures(): iterable
    {
        yield 'runtime exception' => [new RuntimeException('reset failed')];
        yield 'logic exception' => [new LogicException('reset failed')];
        yield 'engine error' => [new Error('reset failed')];
    }

    public function testStabilityFailureDiscardsAndUnlocksCapacity(): void
    {
        $mutex = $this->createMock(Mutex::class);
        $mutex->method('isAcquired')->willReturn(true);
        $mutex->expects(self::once())->method('release');
        $checker = $this->createStub(StabilityChecker::class);
        $checker->method('isStable')->willThrowException(new LogicException('stability failed'));
        $pool = $this->pool(mutex: $mutex, checker: $checker);
        $pool->get();

        $log = $this->captureErrors(fn() => $pool->releaseFromCoroutine($this->cid()));
        self::assertStringContainsString('LogicException: stability failed', $log);

        self::assertSame(0, $pool->getAssignedCount());
        self::assertSame(0, $pool->getFreeCount());
    }

    public function testStabilityIsCheckedAfterReset(): void
    {
        $checker = $this->createStub(StabilityChecker::class);
        $checker->method('isStable')->willReturnCallback(static fn(object $service): bool => $service->stable);
        $pool = $this->pool(static function (object $service): void {
            $service->stable = false;
        }, checker: $checker);
        $pool->get();

        $pool->releaseFromCoroutine($this->cid());

        self::assertSame(0, $pool->getAssignedCount());
        self::assertSame(0, $pool->getFreeCount());
    }

    public function testLazyHigherPriorityPoolIsResetAndReleasedOnce(): void
    {
        $container = new ServicePoolContainer([]);
        $resets = 0;
        $lazy = $this->pool(static function () use (&$resets): void {
            $resets++;
        });
        $first = $this->pool(static function () use ($container, $lazy): void {
            $container->addPool($lazy, 100);
            $lazy->get();
        });
        $container->addPool($first);
        $first->get();

        $container->releaseFromCoroutine($this->cid());
        $container->releaseFromCoroutine($this->cid());

        self::assertSame(1, $resets);
        self::assertSame(0, $lazy->getAssignedCount());
        self::assertSame(1, $lazy->getFreeCount());
        self::assertSame([$lazy, $first], $container->getPools());
    }

    public function testReentrantContainerCleanupKeepsAssignmentsUntilResetCompletes(): void
    {
        $container = new ServicePoolContainer([]);
        $observed = null;
        $pool = null;
        $pool = $this->pool(function () use ($container, &$pool, &$observed): void {
            $container->releaseFromCoroutine($this->cid());
            $observed = $pool->get();
        });
        $container->addPool($pool);
        $original = $pool->get();

        $container->releaseFromCoroutine($this->cid());

        self::assertSame($original, $observed);
        self::assertSame(0, $pool->getAssignedCount());
        self::assertSame(1, $pool->getFreeCount());
    }

    public function testLegacyFailureDoesNotStrandLaterPoolAndDuplicateRegistrationReleasesOnce(): void
    {
        $legacy = $this->createMock(ServicePool::class);
        $legacy->expects(self::once())->method('releaseFromCoroutine')->with($this->cid())
            ->willThrowException(new LogicException('legacy cleanup failed'));
        $later = $this->pool();
        $later->get();
        $container = new ServicePoolContainer([0 => [$legacy, $legacy, $later]]);

        $log = $this->captureErrors(fn() => $container->releaseFromCoroutine($this->cid()));
        self::assertStringContainsString('LogicException: legacy cleanup failed', $log);

        self::assertSame(0, $later->getAssignedCount());
        self::assertSame(1, $later->getFreeCount());
    }

    public function testDrainClearsOnlyFreeInstancesAndPreservesOtherCoroutineAssignment(): void
    {
        $pool = $this->pool();
        $original = $pool->get();
        $container = new ServicePoolContainer([0 => [$pool]]);

        $container->drain($this->cid() + 1);

        self::assertSame(1, $pool->getAssignedCount());
        self::assertSame($original, $pool->get());
        self::assertSame(0, $pool->getFreeCount());

        $container->drain($this->cid());
        $container->drain($this->cid());

        self::assertSame(0, $pool->getAssignedCount());
        self::assertSame(0, $pool->getFreeCount());
        self::assertSame(50, $pool->getInstancesLimit());
        self::assertNotSame($original, $pool->get());
        $pool->releaseFromCoroutine($this->cid());
    }

    private function captureErrors(Closure $operation): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pool-cleanup-');
        self::assertNotFalse($path);
        $previous = ini_set('error_log', $path);
        try {
            $operation();

            return (string) file_get_contents($path);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            unlink($path);
        }
    }

    private function cid(): int
    {
        return new Swoole()->getCoroutineId();
    }

    /** @return BaseServicePool<object> */
    private function pool(?Closure $reset = null, ?Mutex $mutex = null, ?StabilityChecker $checker = null): BaseServicePool
    {
        $resetter = $reset === null ? null : new class ($reset) implements Resetter {
            public function __construct(private readonly Closure $reset) {}

            public function reset(object $service): void
            {
                ($this->reset)($service);
            }
        };

        return new class (new Swoole(), $mutex ?? $this->createStub(Mutex::class), 50, $resetter, $checker) extends BaseServicePool {
            protected function newServiceInstance(): object
            {
                return (object) ['stable' => true];
            }
        };
    }
}
