<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Health\MessengerWorkerHealth;
use App\Shared\Infrastructure\Messenger\WorkerHeartbeatSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
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
    /** @param list<string> $transports */
    #[DataProvider('durableConsumerTransports')]
    public function testConsumerLifecycleAndMeasuredQueueAge(array $transports, string $receivedTransport): void
    {
        $path = sys_get_temp_dir() . '/baander-heartbeat-test-' . bin2hex(random_bytes(12));
        $clock = new MockClock('2026-10-02T10:00:00Z');
        $health = new MessengerWorkerHealth($clock, $path);
        $subscriber = new WorkerHeartbeatSubscriber($health, $clock);
        $receivers = [];
        foreach ($transports as $transport) {
            $receivers[$transport] = new InMemoryTransport();
        }
        $worker = new Worker($receivers, new MessageBus());
        try {
            $subscriber->onStarted(new WorkerStartedEvent($worker));
            self::assertFalse($health->check()->isHealthy());
            $subscriber->onRunning(new WorkerRunningEvent($worker, true));
            self::assertTrue($health->check()->isHealthy());
            $id = ($clock->now()->getTimestamp() * 1000 - 12500) . '-0';
            $subscriber->onReceived(new WorkerMessageReceivedEvent(new Envelope(new \stdClass(), [new TransportMessageIdStamp($id)]), $receivedTransport));
            self::assertSame('busy', $health->check()->details['phase']);
            self::assertSame(12.5, $health->check()->details['lastDeliveryQueueAgeSeconds']);
            self::assertSame($clock->now()->getTimestamp(), $health->check()->details['lastDeliveryAt']);
            $busyHeartbeat = file_get_contents($path);
            $clock->sleep(1);
            $subscriber->onReceived(new WorkerMessageReceivedEvent(new Envelope(new \stdClass(), [new TransportMessageIdStamp('0-0')]), 'failed'));
            self::assertSame($busyHeartbeat, file_get_contents($path), 'An unsupported receiver must not change the latest durable delivery heartbeat.');
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

    /** @return iterable<string, array{list<string>, string}> */
    public static function durableConsumerTransports(): iterable
    {
        yield 'async consumer' => [['async'], 'async'];
        yield 'admitted combined consumer handling scheduler' => [['async', 'scheduler'], 'scheduler'];
        yield 'scheduler consumer' => [['scheduler'], 'scheduler'];
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
        $subscriber->onReceived(new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'failed'));
        $subscriber->onStopped(new WorkerStoppedEvent($worker));
        self::assertFalse($health->check()->isHealthy());
        self::assertFileDoesNotExist($path);
    }
}
