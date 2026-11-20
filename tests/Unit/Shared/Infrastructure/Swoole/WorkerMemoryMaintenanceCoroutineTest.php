<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Tests\Fixtures\Swoole\CoroutineProbe;
use PHPUnit\Framework\TestCase;

final class WorkerMemoryMaintenanceCoroutineTest extends TestCase
{
    public function testOverlappingTicksSkipYieldingMaintenanceAndBorrowedServicesAreReleased(): void
    {
        $this->assertProbe(<<<'CHILD'
$entered = new Swoole\Coroutine\Channel(1);
$resume = new Swoole\Coroutine\Channel(1);
$state = (object) ['pause' => true];
$borrowed = new UnmanagedFactoryServicePool(static fn(): object => new stdClass(), $adapter, $mutex);
$pool = new UnmanagedFactoryServicePool(static fn(): object => new class ($state, $entered, $resume, $borrowed) {
    public function __construct(private object $state, private object $entered, private object $resume, private object $borrowed) {}
    public function __destruct() {
        if (!$this->state->pause) { return; }
        $this->state->pause = false;
        $this->borrowed->get();
        $this->entered->push(true);
        $this->resume->pop();
    }
}, $adapter, $mutex);
$pools = new ServicePoolContainer([0 => [$pool, $borrowed]]);
$maintenance = new WorkerMemoryMaintenance($pools, new CoWrapper($pools, $adapter), $drain, idleSeconds: 0.0);
Swoole\Coroutine\run(static function () use ($pool, $borrowed, $maintenance, $drain, $server, $entered, $resume): void {
    burst($pool);
    $maintenance->onWorkerStarted(new WorkerStartedEvent($server, 0));
    $maintenance->tick();
    $entered->pop();
    $last = Swoole\Coroutine::stats()['coroutine_last_cid'];
    $maintenance->tick();
    check(Swoole\Coroutine::stats()['coroutine_last_cid'] === $last, 'Overlapping tick spawned a coroutine');
    $maintenance->stop();
    check(Swoole\Timer::stats()['num'] === 0, 'Stop retained timer during in-flight maintenance');
    $resume->push(true);
    Swoole\Coroutine::sleep(0.001);
    check($borrowed->getAssignedCount() === 0 && $borrowed->getFreeCount() === 1, 'Destructor borrowing escaped deferred cleanup');
    $maintenance->onWorkerStarted(new WorkerStartedEvent($server, 0));
    $timerIds = iterator_to_array(Swoole\Timer::list());
    check(count($timerIds) === 1, 'Restart did not register exactly one maintenance timer');
    $drain->draining = true;
    $maintenance->tick();
    check(Swoole\Coroutine::stats()['coroutine_last_cid'] === $last, 'Draining worker spawned maintenance');
    $drain->draining = false;
    $maintenance->tick();
    check(Swoole\Coroutine::stats()['coroutine_last_cid'] > $last, 'Completed tick left guard set');
    $maintenance->stop();
    $last = Swoole\Coroutine::stats()['coroutine_last_cid'];
    $maintenance->tick();
    check(Swoole\Coroutine::stats()['coroutine_last_cid'] === $last, 'Stopped maintenance spawned a coroutine');
    // Native timer counts may include coroutine wake-up timers; check the registered timer itself.
    check(!Swoole\Timer::exists($timerIds[0]) && iterator_to_array(Swoole\Timer::list()) === [], 'Stopped maintenance retained its timer');
});
CHILD);
    }

    public function testSchedulingFailureClearsGuardAndLaterTickCanRetry(): void
    {
        $this->assertProbe(<<<'CHILD'
$pool = new UnmanagedFactoryServicePool(static fn(): object => new stdClass(), $adapter, $mutex);
$pools = new ServicePoolContainer([0 => [$pool]]);
$maintenance = new WorkerMemoryMaintenance($pools, new CoWrapper($pools, $adapter), $drain, idleSeconds: 0.0);
Swoole\Coroutine\run(static fn() => burst($pool));
Swoole\Coroutine::set(['max_coroutine' => 1]);
set_error_handler(static fn(int $severity, string $message): bool => $severity === E_WARNING && str_contains($message, 'exceed max number of coroutine'));
Swoole\Coroutine\run(static function () use ($maintenance, $server, $pool): void {
    $maintenance->onWorkerStarted(new WorkerStartedEvent($server, 0));
    $maintenance->tick();
    check($pool->getFreeCount() === 2, 'Failed scheduling touched free instances');
    $maintenance->stop();
});
restore_error_handler();
Swoole\Coroutine::set(['max_coroutine' => 8]);
Swoole\Coroutine\run(static function () use ($maintenance, $server, $pool): void {
    $maintenance->onWorkerStarted(new WorkerStartedEvent($server, 0));
    $maintenance->tick();
    check($pool->getFreeCount() === 1, 'Scheduling failure left ticking guard set');
    $maintenance->stop();
});
CHILD, 'Unable to create coroutine.');
    }

    private function assertProbe(string $body, ?string $error = null): void
    {
        $script = <<<'CHILD'
require $argv[1];
use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Infrastructure\Swoole\WorkerMemoryMaintenance;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\UnmanagedFactoryServicePool;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as Adapter;
use SwooleBundle\SwooleBundle\Component\Locking\Mutex;
$adapter = new Adapter();
$mutex = new class implements Mutex {
    public function acquire(): void { throw new RuntimeException('Unexpected pool exhaustion'); }
    public function release(): void {}
    public function isAcquired(): bool { return false; }
};
$drain = new class implements ServerDrainStateInterface {
    public bool $draining = false;
    public function isDraining(): bool { return $this->draining; }
};
$server = new Swoole\Server('127.0.0.1', 0);
function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function burst(object $pool): void {
    $release = new Swoole\Coroutine\Channel(2);
    $done = new Swoole\Coroutine\Channel(2);
    for ($i = 0; $i < 2; $i++) {
        Swoole\Coroutine::create(static function () use ($pool, $release, $done): void {
            $pool->get(); $release->pop();
            $pool->releaseFromCoroutine(Swoole\Coroutine::getCid()); $done->push(true);
        });
    }
    for ($i = 0; $i < 2; $i++) { $release->push(true); }
    for ($i = 0; $i < 2; $i++) { $done->pop(); }
}
CHILD;
        $process = CoroutineProbe::create(['-r', $script . "\n" . $body, dirname(__DIR__, 5) . '/vendor/autoload.php']);
        $process->setTimeout(5);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        if ($error !== null) {
            self::assertStringContainsString($error, $process->getErrorOutput());
        }
    }
}
