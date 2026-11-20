<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Tests\Fixtures\Swoole\CoroutineProbe;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\IdleServicePool;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;

final class ServicePoolIdleTest extends TestCase
{
    public function testExpiredSparesAreReleasedWhileRecentAndBorrowedInstancesSurvive(): void
    {
        $this->assertProbe(<<<'CHILD'
$state = (object) ['created' => 0, 'destroyed' => 0];
$pool = new UnmanagedFactoryServicePool(static function () use ($state): object {
    $state->created++;
    return new class ($state) {
        public function __construct(private object $state) {}
        public function __destruct() { $this->state->destroyed++; }
    };
}, $adapter, $mutex, 4);
$pools = new ServicePoolContainer([0 => [$pool], 1 => [$pool]]);
Swoole\Coroutine\run(static function () use ($pool, $pools, $state, $adapter): void {
    $releases = [];
    $done = new Swoole\Coroutine\Channel(4);
    for ($i = 0; $i < 4; $i++) {
        $release = new Swoole\Coroutine\Channel(1);
        $releases[] = $release;
        Swoole\Coroutine::create(static function () use ($pool, $release, $done, $adapter): void {
            $pool->get();
            $release->pop();
            $pool->releaseFromCoroutine($adapter->getCoroutineId());
            $done->push(true);
        });
    }
    for ($i = 0; $i < 3; $i++) { $releases[$i]->push(true); $done->pop(); }
    Swoole\Coroutine::sleep(0.03);
    $releases[3]->push(true);
    $done->pop();
    $borrowed = $pool->get();
    $removed = $pools->pruneFree(0.02);
    if ($removed !== 2 || $state->destroyed !== 2 || $pool->getFreeCount() !== 1 || $pool->getAssignedCount() !== 1 || $pool->get() !== $borrowed || $pool->getInstancesLimit() !== 4) {
        throw new RuntimeException('Expired, recent or borrowed ownership changed incorrectly');
    }
    $pool->releaseFromCoroutine($adapter->getCoroutineId());
    unset($borrowed);
    burst($pool, 2);
    if ($state->created !== 4 || $pool->getFreeCount() !== 2 || $pool->getAssignedCount() !== 0) {
        throw new RuntimeException('Returned instances were not reusable');
    }
    if ($pools->pruneFree(0.0) !== 1 || $pools->pruneFree(0.0) !== 0 || $pool->getFreeCount() !== 1) {
        throw new RuntimeException('Warm instance was not retained');
    }
});
CHILD);
    }

    public function testYieldingDestructorsAllowBorrowAndReleaseAndSerializePendingDrain(): void
    {
        $this->assertProbe(<<<'CHILD'
$state = (object) ['active' => 0, 'peak' => 0, 'destroyed' => 0, 'created' => 0, 'pause' => true];
$entered = new Swoole\Coroutine\Channel(1);
$resume = new Swoole\Coroutine\Channel(1);
$pool = new UnmanagedFactoryServicePool(static function () use ($state, $entered, $resume): object {
    $state->created++;
    return new class ($state, $entered, $resume) {
        public function __construct(private object $state, private object $entered, private object $resume) {}
        public function __destruct() {
            $this->state->active++;
            $this->state->peak = max($this->state->peak, $this->state->active);
            if ($this->state->pause) {
                $this->state->pause = false;
                $this->entered->push(true);
                $this->resume->pop();
            }
            if (Swoole\Coroutine::getCid() !== -1) { Swoole\Coroutine::sleep(0.001); }
            $this->state->active--;
            $this->state->destroyed++;
        }
    };
}, $adapter, $mutex, 4);
$pools = new ServicePoolContainer([0 => [$pool]]);
Swoole\Coroutine\run(static function () use ($pool, $pools, $state, $entered, $resume, $adapter): void {
    burst($pool, 4);
    $done = new Swoole\Coroutine\Channel(1);
    Swoole\Coroutine::create(static function () use ($pools, $done): void { $done->push($pools->pruneFree(0.0)); });
    $entered->pop();
    if ($pool->getFreeCount() !== 1) { throw new RuntimeException('Candidates were not detached before destruction'); }
    $pool->get();
    if ($state->created !== 4 || $pools->pruneFree(0.0) !== 0) { throw new RuntimeException('Concurrent maintenance or borrow violated ownership'); }
    $pools->drain($adapter->getCoroutineId());
    if ($pool->getAssignedCount() !== 0 || $pool->getFreeCount() !== 1 || $state->peak !== 1) {
        throw new RuntimeException('Drain did not release promptly or overlapped destructors');
    }
    $resume->push(true);
    if ($done->pop() !== 3 || $pool->getFreeCount() !== 0 || $state->destroyed !== 4 || $state->peak !== 1) {
        throw new RuntimeException('Pending drain lost instances or overlapped destruction');
    }
    burst($pool, 2);
    if ($pools->pruneFree(0.0) !== 1 || $pool->getFreeCount() !== 1) { throw new RuntimeException('Maintenance guard was not cleared'); }
    $pools->drain($adapter->getCoroutineId());
});
CHILD);
    }

    public function testResettingAndPreparedAssignmentsAreNeverPruned(): void
    {
        $this->assertProbe(<<<'CHILD'
$pool = null;
$observed = 0;
$resetter = new class (static function () use (&$pool, &$observed): void {
    if ($pool->pruneFree(0.0, 0) !== 0 || $pool->getAssignedCount() !== 1) {
        throw new RuntimeException('Resetting assignment was pruned');
    }
    $observed++;
}) implements SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Resetter {
    public function __construct(private Closure $callback) {}
    public function reset(object $service): void { ($this->callback)(); }
};
$pool = new UnmanagedFactoryServicePool(static fn(): object => new stdClass(), $adapter, $mutex, 4, $resetter);
Swoole\Coroutine\run(static function () use ($pool, &$observed, $adapter): void {
    $original = $pool->get();
    $cid = $adapter->getCoroutineId();
    $pool->resetAssigned($cid);
    if ($pool->pruneFree(0.0, 0) !== 0 || $pool->get() !== $original || $observed !== 1) {
        throw new RuntimeException('Prepared assignment was pruned');
    }
    $pool->releaseAssigned($cid);
    unset($original);
    if ($pool->pruneFree(0.0, 0) !== 1 || $pool->getFreeCount() !== 0) { throw new RuntimeException('Released assignment did not become eligible'); }
});
CHILD);
    }

    public function testContainerDeduplicatesPoolsAndContinuesAfterPruneFailure(): void
    {
        $failed = $this->createMock(IdleServicePool::class);
        $failed->expects(self::exactly(2))->method('pruneFree')->with(12.0, 2)->willThrowException(new \RuntimeException('prune unavailable'));
        $later = $this->createMock(IdleServicePool::class);
        $later->expects(self::exactly(2))->method('pruneFree')->with(12.0, 2)->willReturn(3);
        $container = new ServicePoolContainer([0 => [$failed, $later], 1 => [$failed, $later]]);
        $log = tmpfile();
        self::assertIsResource($log);
        $previous = ini_set('error_log', stream_get_meta_data($log)['uri']);
        try {
            $first = $container->pruneFree(12.0, 2);
            // A failure must leave the next maintenance pass usable.
            $second = $container->pruneFree(12.0, 2);
            self::assertSame([3, 3], [$first, $second]);
            rewind($log);
            self::assertStringContainsString('prune unavailable', stream_get_contents($log));
        } finally {
            ini_set('error_log', $previous);
            fclose($log);
        }
    }

    private function assertProbe(string $body): void
    {
        $script = <<<'CHILD'
require $argv[1];
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\UnmanagedFactoryServicePool;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as Adapter;
use SwooleBundle\SwooleBundle\Component\Locking\Mutex;
$adapter = new Adapter();
$mutex = new class implements Mutex {
    public function acquire(): void { throw new RuntimeException('Pool limit unexpectedly reached'); }
    public function release(): void {}
    public function isAcquired(): bool { return false; }
};
function burst(object $pool, int $count): void {
    $release = new Swoole\Coroutine\Channel($count);
    $done = new Swoole\Coroutine\Channel($count);
    for ($i = 0; $i < $count; $i++) {
        Swoole\Coroutine::create(static function () use ($pool, $release, $done): void {
            $pool->get();
            $release->pop();
            $pool->releaseFromCoroutine(Swoole\Coroutine::getCid());
            $done->push(true);
        });
    }
    for ($i = 0; $i < $count; $i++) { $release->push(true); }
    for ($i = 0; $i < $count; $i++) { $done->pop(); }
}
CHILD;
        $process = CoroutineProbe::create(['-r', $script . "\n" . $body, dirname(__DIR__, 5) . '/vendor/autoload.php']);
        $process->setTimeout(5);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
    }
}
