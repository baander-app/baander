<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Messenger\WorkerServicePoolResetSubscriber;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePool;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\Service\ResetInterface;

final class WorkerServicePoolResetSubscriberTest extends TestCase
{
    public function testCompletedDeliveryReleasesEveryPoolForOnlyTheCurrentCoroutine(): void
    {
        $swoole = new Swoole();
        $firstPool = $this->createMock(ServicePool::class);
        $secondPool = $this->createMock(ServicePool::class);
        $pools = new ServicePoolContainer([10 => [$firstPool], 0 => [$secondPool]]);
        $subscriber = new WorkerServicePoolResetSubscriber($pools, $swoole);
        $worker = $this->createWorker();

        Coroutine\run(function () use ($swoole, $firstPool, $secondPool, $subscriber, $worker): void {
            $context = $swoole->getCoroutineId();
            self::assertGreaterThan(0, $context);
            $firstPool->expects($this->once())->method('releaseFromCoroutine')->with($context);
            $secondPool->expects($this->once())->method('releaseFromCoroutine')->with($context);

            $subscriber->onRunning(new WorkerRunningEvent($worker, false));
        });
    }

    public function testIdlePollDoesNotReleasePools(): void
    {
        $pool = $this->createMock(ServicePool::class);
        $pool->expects($this->never())->method('releaseFromCoroutine');
        $subscriber = new WorkerServicePoolResetSubscriber(new ServicePoolContainer([0 => [$pool]]), new Swoole());

        $subscriber->onRunning(new WorkerRunningEvent($this->createWorker(), true));
    }

    public function testStopReleasesPoolsWithoutAPrecedingDelivery(): void
    {
        $swoole = new Swoole();
        $pool = $this->createMock(ServicePool::class);
        $pool->expects($this->once())->method('releaseFromCoroutine')->with($swoole->getCoroutineId());
        $subscriber = new WorkerServicePoolResetSubscriber(new ServicePoolContainer([0 => [$pool]]), $swoole);

        $subscriber->onStopped(new WorkerStoppedEvent($this->createWorker()));
    }

    public function testDeliveryReleaseRunsBeforeSymfonyServiceReset(): void
    {
        $order = [];
        $pool = $this->createMock(ServicePool::class);
        $pool->expects($this->once())->method('releaseFromCoroutine')->willReturnCallback(
            static function (int $context) use (&$order): void {
                $order[] = 'pool release';
            },
        );
        $resetter = $this->createMock(ResetInterface::class);
        $resetter->expects($this->once())->method('reset')->willReturnCallback(
            static function () use (&$order): void {
                $order[] = 'Symfony reset';
            },
        );
        $dispatcher = new EventDispatcher();
        // Register Symfony first so registration order cannot hide a wrong priority.
        $dispatcher->addSubscriber(new ResetServicesListener($resetter));
        $dispatcher->addSubscriber(new WorkerServicePoolResetSubscriber(new ServicePoolContainer([0 => [$pool]]), new Swoole()));

        $dispatcher->dispatch(new WorkerRunningEvent($this->createWorker(), false));

        self::assertSame(['pool release', 'Symfony reset'], $order);
    }

    public function testStopReleaseRunsAfterStopEffectsAndBeforeSymfonyServiceReset(): void
    {
        $order = [];
        $pool = $this->createMock(ServicePool::class);
        $pool->expects($this->once())->method('releaseFromCoroutine')->willReturnCallback(
            static function (int $context) use (&$order): void {
                $order[] = 'pool release';
            },
        );
        $resetter = $this->createMock(ResetInterface::class);
        $resetter->expects($this->once())->method('reset')->willReturnCallback(
            static function () use (&$order): void {
                $order[] = 'Symfony reset';
            },
        );
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new WorkerServicePoolResetSubscriber(new ServicePoolContainer([0 => [$pool]]), new Swoole()));
        // Register the ordinary stop listener later so equal priorities expose an early release.
        $dispatcher->addListener(WorkerStoppedEvent::class, static function (WorkerStoppedEvent $event) use (&$order): void {
            $order[] = 'stopped';
        });
        $dispatcher->addSubscriber(new ResetServicesListener($resetter));

        $dispatcher->dispatch(new WorkerStoppedEvent($this->createWorker()));

        self::assertSame(['stopped', 'pool release', 'Symfony reset'], $order);
    }

    public function testDeliveryAndStopDelegateRepeatedReleaseToThePools(): void
    {
        $swoole = new Swoole();
        $pool = $this->createMock(ServicePool::class);
        $pool->expects($this->exactly(2))->method('releaseFromCoroutine')->with($swoole->getCoroutineId());
        $subscriber = new WorkerServicePoolResetSubscriber(new ServicePoolContainer([0 => [$pool]]), $swoole);
        $worker = $this->createWorker();

        $subscriber->onRunning(new WorkerRunningEvent($worker, false));
        $subscriber->onStopped(new WorkerStoppedEvent($worker));
    }

    private function createWorker(): Worker
    {
        return new Worker(['async' => new InMemoryTransport()], new MessageBus());
    }
}
