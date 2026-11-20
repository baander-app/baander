<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Infrastructure\Swoole\WorkerMemoryMaintenance;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Server;
use Swoole\Timer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\UnmanagedFactoryServicePool;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerExitedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as Adapter;
use SwooleBundle\SwooleBundle\Component\Locking\Mutex;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WorkerMemoryMaintenanceTest extends TestCase
{
    protected function tearDown(): void
    {
        Timer::clearAll();
    }

    public function testHttpWorkerStartsOneTimerAndReloadOrStopClearsIt(): void
    {
        $maintenance = $this->maintenance(new ServicePoolContainer([]));
        $server = new Server('127.0.0.1', 0);

        $maintenance->onWorkerStarted(new WorkerStartedEvent($server, 2));
        $maintenance->onWorkerStarted(new WorkerStartedEvent($server, 2));
        $counts = [Timer::stats()['num']];

        $maintenance->onWorkerExited(new WorkerExitedEvent($server, 2));
        $counts[] = Timer::stats()['num'];

        $maintenance->onWorkerStarted(new WorkerStartedEvent($server, 2));
        $maintenance->onWorkerStopped(new WorkerStoppedEvent($server, 2));
        $maintenance->stop();
        $counts[] = Timer::stats()['num'];
        self::assertSame([1, 0, 0], $counts);
    }

    public function testTaskWorkerStartsNoTimer(): void
    {
        $maintenance = $this->maintenance(new ServicePoolContainer([]));
        $server = new Server('127.0.0.1', 0);
        $server->taskworker = true;

        $maintenance->onWorkerStarted(new WorkerStartedEvent($server, 4));

        self::assertSame(0, Timer::stats()['num']);
    }

    public function testMaintenanceReclaimsSpareObjectsAndPreservesBorrowers(): void
    {
        $adapter = new Adapter();
        /** @var UnmanagedFactoryServicePool<object> $pool */
        $pool = new UnmanagedFactoryServicePool(static fn(): object => new \stdClass(), $adapter, $this->createStub(Mutex::class));
        $pools = new ServicePoolContainer([0 => [$pool]]);
        $maintenance = $this->maintenance($pools);
        $server = new Server('127.0.0.1', 0);
        $maintenance->onWorkerStarted(new WorkerStartedEvent($server, 0));

        Coroutine\run(static function () use ($pool, $adapter, $maintenance): void {
            $borrowed = $pool->get();
            $ready = new Coroutine\Channel(2);
            $release = new Coroutine\Channel(2);
            $done = new Coroutine\Channel(2);
            for ($i = 0; $i < 2; $i++) {
                Coroutine::create(static function () use ($pool, $adapter, $ready, $release, $done): void {
                    $pool->get();
                    $ready->push(true);
                    $release->pop();
                    $pool->releaseFromCoroutine($adapter->getCoroutineId());
                    $done->push(true);
                });
            }
            for ($i = 0; $i < 2; $i++) {
                $ready->pop();
            }
            for ($i = 0; $i < 2; $i++) {
                $release->push(true);
            }
            for ($i = 0; $i < 2; $i++) {
                $done->pop();
            }
            self::assertSame(2, $pool->getFreeCount());

            $maintenance->tick();

            self::assertSame(1, $pool->getFreeCount());
            self::assertSame(1, $pool->getAssignedCount());
            self::assertSame($borrowed, $pool->get());
            $pool->releaseFromCoroutine($adapter->getCoroutineId());
            $maintenance->stop();
        });
    }

    private function maintenance(ServicePoolContainer $pools): WorkerMemoryMaintenance
    {
        $drain = $this->createStub(ServerDrainStateInterface::class);
        $drain->method('isDraining')->willReturn(false);

        return new WorkerMemoryMaintenance($pools, new CoWrapper($pools, new Adapter()), $drain, idleSeconds: 0.0);
    }
}
