<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Health;

use App\Shared\Application\Port\AdminAlertPortInterface;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Infrastructure\Health\HealthAlertService;
use App\Shared\Infrastructure\Health\HealthAlertTable;
use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Infrastructure\Health\HealthMonitorSubscriber;
use App\Shared\Infrastructure\Health\MessengerWorkerHealth;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Swoole\Server;
use Swoole\Timer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;
use Symfony\Component\Clock\MockClock;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HealthMonitorSubscriberTest extends TestCase
{
    /** Called from inside the PostgreSQL check, while a tick is running. */
    private ?\Closure $duringCheck = null;
    private int $checks = 0;
    private MonitorRecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new MonitorRecordingLogger();
    }

    protected function tearDown(): void
    {
        Timer::clearAll();
    }

    public function testHttpWorkerZeroKeepsATimerUntilItStops(): void
    {
        $monitor = $this->monitor();
        $server = new Server('127.0.0.1', 0);

        $monitor->onWorkerStarted(new WorkerStartedEvent($server, 0));

        self::assertSame(1, Timer::stats()['num']);
        // The first check waits one interval.
        self::assertSame(0, $this->checks);

        $monitor->onWorkerStopped(new WorkerStoppedEvent($server, 0));

        self::assertSame(0, Timer::stats()['num']);
    }

    public function testATaskWorkerStartsNoTimer(): void
    {
        $monitor = $this->monitor();
        $server = new Server('127.0.0.1', 0);
        $server->taskworker = true;

        $monitor->onWorkerStarted(new WorkerStartedEvent($server, 0));

        self::assertSame(0, Timer::stats()['num']);
    }

    public function testHttpWorkerOneStartsNoTimer(): void
    {
        $monitor = $this->monitor();

        $monitor->onWorkerStarted(new WorkerStartedEvent(new Server('127.0.0.1', 0), 1));

        self::assertSame(0, Timer::stats()['num']);
    }

    public function testATickThatStartsWhileThePreviousTickStillRunsReturnsWithoutChecking(): void
    {
        $monitor = $this->monitor();
        $this->duringCheck = static function () use ($monitor): void {
            $monitor->tick();
        };

        $monitor->tick();
        self::assertSame(1, $this->checks);
        // A hung check would silence the monitor, so each skipped tick leaves a trace.
        self::assertSame(['warning'], array_column($this->logger->records, 0));
        self::assertStringContainsString('previous tick', $this->logger->records[0][1]);

        // Once the running tick has finished, the next one checks again.
        $this->duringCheck = null;
        $monitor->tick();
        self::assertSame(2, $this->checks);
        self::assertCount(1, $this->logger->records);
    }

    public function testATickWhoseAlertPathThrowsLogsTheErrorAndTheNextTickRunsAgain(): void
    {
        // The checks catch their own failures, so the throw comes from the alert path.
        $clock = new class () implements ClockInterface {
            public int $failures = 1;

            public function now(): \DateTimeImmutable
            {
                if ($this->failures > 0) {
                    --$this->failures;
                    throw new \RuntimeException('Clock unavailable');
                }

                return new \DateTimeImmutable('2026-10-10 12:00:00 UTC');
            }
        };
        $monitor = $this->monitor($clock);

        $monitor->tick();

        self::assertSame(1, $this->checks);
        self::assertSame(['error'], array_column($this->logger->records, 0));
        $exception = $this->logger->records[0][2]['exception'] ?? null;
        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertSame('Clock unavailable', $exception->getMessage());

        $monitor->tick();

        self::assertSame(2, $this->checks, 'The failed tick released the monitor for the next one.');
        self::assertCount(1, $this->logger->records);
    }

    private function monitor(ClockInterface $alertClock = new MockClock()): HealthMonitorSubscriber
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn(1);
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willReturnCallback(function () use ($result): Result {
            ++$this->checks;
            $during = $this->duringCheck;
            $this->duringCheck = null;
            if ($during !== null) {
                $during();
            }

            return $result;
        });
        $redis = $this->createStub(RedisClientFactory::class);
        $checks = new HealthCheckService(
            connection: $connection,
            redisClientFactory: $redis,
            appEnv: 'prod',
            appSecret: str_repeat('secure-', 6),
            appUrl: 'https://baander.app',
            oauthPrivateKeyPath: '',
            oauthPublicKeyPath: '',
            vapidPublicKey: '',
            vapidPrivateKey: '',
            messengerWorkerHealth: new MessengerWorkerHealth($redis, new MockClock(), new HealthAlertTable()),
        );
        $alerts = new HealthAlertService(
            $checks,
            $this->createStub(AdminAlertPortInterface::class),
            $this->createStub(SystemSettingsPortInterface::class),
            new HealthAlertTable(),
            $alertClock,
            new NullLogger(),
        );

        return new HealthMonitorSubscriber($alerts, $this->logger, 60);
    }
}

final class MonitorRecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $records = [];

    /** @param mixed[] $context */
    public function log(mixed $level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message, $context];
    }
}
