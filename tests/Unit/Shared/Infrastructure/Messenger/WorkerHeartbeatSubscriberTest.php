<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Health\HealthStatus;
use App\Shared\Infrastructure\Health\HealthAlertTable;
use App\Shared\Infrastructure\Health\MessengerWorkerHealth;
use App\Shared\Infrastructure\Messenger\WorkerHeartbeatSubscriber;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\Redis\ArrayRedis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Redis;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleAlarmEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
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
    private ArrayRedis $redis;
    private MockClock $clock;
    private MessengerWorkerHealth $health;
    private EventDispatcher $dispatcher;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->redis = new ArrayRedis();
        $this->clock = new MockClock('2026-10-02T10:00:00Z');
        $redis = $this->redis;
        $this->health = new MessengerWorkerHealth(
            new RedisClientFactory('redis://127.0.0.1:6379', connectionFactory: static fn (): Redis => $redis),
            $this->clock,
            new HealthAlertTable(),
        );
        $this->logger = new RecordingLogger();
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addSubscriber(new WorkerHeartbeatSubscriber($this->health, $this->clock, $this->logger));
    }

    /** @param list<string> $transports */
    #[DataProvider('durableConsumerTransports')]
    public function testConsumerLifecycleAndMeasuredQueueAge(array $transports, string $receivedTransport): void
    {
        $worker = $this->worker($transports);

        $this->dispatcher->dispatch(new WorkerStartedEvent($worker));
        self::assertSame('starting', $this->health->check()->details['phase']);
        $this->dispatcher->dispatch(new WorkerRunningEvent($worker, true));
        self::assertSame('idle', $this->health->check()->details['phase']);
        self::assertTrue($this->health->check()->isHealthy());

        $id = ($this->clock->now()->getTimestamp() * 1000 - 12500) . '-0';
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new \stdClass(), [new TransportMessageIdStamp($id)]), $receivedTransport));
        $busy = $this->health->check();
        self::assertSame('busy', $busy->details['phase']);
        self::assertSame(12.5, $busy->details['lastDeliveryQueueAgeSeconds']);
        self::assertSame($this->clock->now()->getTimestamp(), $busy->details['lastDeliveryAt']);

        $busyHeartbeat = $this->redis->values;
        $this->clock->sleep(1);
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new \stdClass(), [new TransportMessageIdStamp('0-0')]), 'failed'));
        self::assertSame($busyHeartbeat, $this->redis->values, 'An unsupported receiver must not change the latest durable delivery heartbeat.');

        $this->dispatcher->dispatch(new WorkerRunningEvent($worker, false));
        self::assertSame('idle', $this->health->check()->details['phase']);
        $this->dispatcher->dispatch(new WorkerStoppedEvent($worker));
        $stopped = $this->health->check();
        self::assertSame('stopped', $stopped->details['phase']);
        // A fresh 'stopped' may be a recycle whose replacement has yet to start.
        self::assertSame(HealthStatus::NotAvailable, $stopped->status);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function durableConsumerTransports(): iterable
    {
        yield 'async consumer' => [['async'], 'async'];
        yield 'admitted combined consumer handling scheduler' => [['async', 'scheduler'], 'scheduler'];
        yield 'scheduler consumer' => [['scheduler'], 'scheduler'];
    }

    public function testTheKeepaliveAlarmKeepsALongHandlerHealthy(): void
    {
        $worker = $this->worker(['async', 'scheduler']);
        $this->dispatcher->dispatch(new WorkerStartedEvent($worker));
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'async'));

        // Four keepalive intervals pass with the handler still running; 120 s exceeds the busy window.
        for ($interval = 1; $interval <= 4; ++$interval) {
            $this->clock->sleep(30);
            $this->dispatcher->dispatch($this->alarm());
            $result = $this->health->check();
            self::assertSame(HealthStatus::Healthy, $result->status, sprintf('after %d s of handling', $interval * 30));
            self::assertSame('busy', $result->details['phase']);
        }
    }

    public function testTheKeepaliveAlarmDoesNotTouchAnIdleOrInactiveConsumer(): void
    {
        $this->dispatcher->dispatch($this->alarm());
        self::assertSame([], $this->redis->values, 'A console command that is not a durable consumer writes no heartbeat.');

        $worker = $this->worker(['async']);
        $this->dispatcher->dispatch(new WorkerStartedEvent($worker));
        $this->dispatcher->dispatch(new WorkerRunningEvent($worker, true));
        $idle = $this->redis->values;
        $this->clock->sleep(30);
        $this->dispatcher->dispatch($this->alarm());
        self::assertSame($idle, $this->redis->values);
    }

    public function testFailureQueueMaintenanceDoesNotAdvertiseAsyncReadiness(): void
    {
        $worker = $this->worker(['failed']);
        $this->dispatcher->dispatch(new WorkerStartedEvent($worker));
        $this->dispatcher->dispatch(new WorkerRunningEvent($worker, true));
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'failed'));
        $this->dispatcher->dispatch($this->alarm());
        $this->dispatcher->dispatch(new WorkerStoppedEvent($worker));

        self::assertSame([], $this->redis->values);
        self::assertSame(HealthStatus::NotAvailable, $this->health->check()->status);
    }

    public function testAFailedHeartbeatWriteIsLoggedAndTheConsumerKeepsRunning(): void
    {
        $worker = $this->worker(['async']);
        $this->redis->unreachable = true;

        $this->dispatcher->dispatch(new WorkerStartedEvent($worker));
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'async'));
        $this->dispatcher->dispatch($this->alarm());
        $this->clock->sleep(10);
        $this->dispatcher->dispatch(new WorkerRunningEvent($worker, true));
        $this->dispatcher->dispatch(new WorkerStoppedEvent($worker));

        self::assertSame(['starting', 'busy', 'busy', 'idle', 'stopped'], array_map(static fn (array $entry): string => $entry[2]['phase'], $this->logger->records));
        foreach ($this->logger->records as [$level, , $context]) {
            self::assertSame('warning', $level);
            self::assertInstanceOf(\RedisException::class, $context['exception']);
        }

        $this->redis->unreachable = false;
        $this->clock->sleep(10);
        $this->dispatcher->dispatch(new WorkerStartedEvent($worker));
        $this->dispatcher->dispatch(new WorkerRunningEvent($worker, true));
        self::assertSame(HealthStatus::Healthy, $this->health->check()->status, 'Writes resume once Redis answers again.');
    }

    /** @param list<string> $transports */
    private function worker(array $transports): Worker
    {
        $receivers = [];
        foreach ($transports as $transport) {
            $receivers[$transport] = new InMemoryTransport();
        }

        return new Worker($receivers, new MessageBus());
    }

    private function alarm(): ConsoleAlarmEvent
    {
        return new ConsoleAlarmEvent(new Command('messenger:consume'), new ArrayInput([]), new NullOutput());
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message, $context];
    }
}
