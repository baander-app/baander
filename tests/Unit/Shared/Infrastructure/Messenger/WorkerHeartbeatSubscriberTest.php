<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Health\MessengerWorkerHealth;
use App\Shared\Infrastructure\Messenger\WorkerHeartbeatSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class WorkerHeartbeatSubscriberTest extends TestCase
{
    public function testConsumerLifecycleAndMeasuredQueueAge(): void
    {
        $path = sys_get_temp_dir() . '/baander-heartbeat-test-' . bin2hex(random_bytes(12));
        $clock = new MockClock('2026-10-02T10:00:00Z');
        $health = new MessengerWorkerHealth($clock, $path);
        $subscriber = new WorkerHeartbeatSubscriber($health, $clock);
        $worker = new Worker(['async' => new InMemoryTransport()], new MessageBus());
        try {
            $subscriber->onStarted(new WorkerStartedEvent($worker));
            self::assertFalse($health->check()->isHealthy());
            $subscriber->onRunning(new WorkerRunningEvent($worker, true));
            self::assertTrue($health->check()->isHealthy());
            $id = ($clock->now()->getTimestamp() * 1000 - 12500) . '-0';
            $subscriber->onReceived(new WorkerMessageReceivedEvent(new Envelope(new \stdClass(), [new TransportMessageIdStamp($id)]), 'async'));
            self::assertSame('busy', $health->check()->details['phase']);
            self::assertSame(12.5, $health->check()->details['lastDeliveryQueueAgeSeconds']);
            $clock->sleep(120);
            self::assertTrue($health->check()->isHealthy());
            $subscriber->onRunning(new WorkerRunningEvent($worker, false));
            self::assertSame('idle', $health->check()->details['phase']);
            $subscriber->onStopped(new WorkerStoppedEvent($worker));
            self::assertFalse($health->check()->isHealthy());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testFailureQueueMaintenanceDoesNotAdvertiseAsyncReadiness(): void
    {
        $path = sys_get_temp_dir() . '/baander-heartbeat-test-' . bin2hex(random_bytes(12));
        $clock = new MockClock();
        $health = new MessengerWorkerHealth($clock, $path);
        $subscriber = new WorkerHeartbeatSubscriber($health, $clock);
        $worker = new Worker(['failed' => new InMemoryTransport()], new MessageBus());
        $subscriber->onStarted(new WorkerStartedEvent($worker));
        $subscriber->onRunning(new WorkerRunningEvent($worker, true));
        self::assertFalse($health->check()->isHealthy());
        self::assertFileDoesNotExist($path);
    }
}
