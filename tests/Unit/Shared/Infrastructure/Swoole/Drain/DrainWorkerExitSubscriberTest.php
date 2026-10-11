<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Drain;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Domain\Port\QualityLadderPortInterface;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Infrastructure\Swoole\CpuGpuSampler;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\QoL\Infrastructure\Swoole\MidStreamMonitor;
use App\QoL\Infrastructure\Swoole\QoLWorkerStartupSubscriber;
use App\Shared\Application\Port\AdminAlertPortInterface;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Infrastructure\Health\HealthAlertService;
use App\Shared\Infrastructure\Health\HealthAlertTable;
use App\Shared\Infrastructure\Health\HealthCheckService;
use App\Shared\Infrastructure\Health\HealthMonitorSubscriber;
use App\Shared\Infrastructure\Health\MessengerWorkerHealth;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Swoole\Drain\DrainBudget;
use App\Shared\Infrastructure\Swoole\Drain\DrainStateTable;
use App\Shared\Infrastructure\Swoole\Drain\DrainWorkerExitSubscriber;
use App\Shared\Infrastructure\Swoole\Drain\WorkerDrain;
use App\Shared\Infrastructure\Swoole\WorkerMemoryReporter;
use App\Shared\Infrastructure\Swoole\WorkerMemoryTable;
use App\Tests\Fixtures\QoL\InMemoryAlgorithmProfileStore;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Swoole\Server;
use Swoole\Timer;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerExitedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Coroutine\Watchdog\CoroutineWatchdog;
use SwooleBundle\SwooleBundle\Server\WorkerHandler\CoroutineWatchdogWorkerStartHandler;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Starts every PHP timer an HTTP worker 0 runs, as the web server starts them, then exits the
 * worker: a draining worker must leave no timer behind, since any timer holds a stopping worker
 * until Swoole kills it at max_wait_time. A reloading worker keeps them until WorkerStop.
 * (The transcode loop lock renewal timers are covered by TranscodeWorkerExitSubscriberTest.)
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DrainWorkerExitSubscriberTest extends TestCase
{
    /** The drain tick, the health monitor, the memory report, the coroutine watchdog, the CPU sampler and the mid-stream monitor. */
    private const int WORKER_ZERO_TIMERS = 6;

    private string $directory;
    private DrainStateTable $state;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-drain-exit-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory));
        $this->state = new DrainStateTable();
        $this->state->boot();
    }

    protected function tearDown(): void
    {
        Timer::clearAll();
        array_map(unlink(...), glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    public function testADrainingWorkerLeavesNoTimerWhenItExits(): void
    {
        [$server, $dispatcher] = $this->startWorkerZero();
        self::assertSame(self::WORKER_ZERO_TIMERS, Timer::stats()['num']);

        $this->state->begin(hrtime(true));
        $dispatcher->dispatch(new WorkerExitedEvent($server, 0), WorkerExitedEvent::NAME);

        self::assertSame([], iterator_to_array(Timer::list()));
        // Swoole runs WorkerExit again until the event loop is empty.
        $dispatcher->dispatch(new WorkerExitedEvent($server, 0), WorkerExitedEvent::NAME);
        self::assertSame([], iterator_to_array(Timer::list()));
    }

    public function testAReloadingWorkerKeepsItsTimersOnWorkerExit(): void
    {
        [$server, $dispatcher] = $this->startWorkerZero();

        $dispatcher->dispatch(new WorkerExitedEvent($server, 0), WorkerExitedEvent::NAME);
        $dispatcher->dispatch(new WorkerExitedEvent($server, 0), WorkerExitedEvent::NAME);

        self::assertSame(self::WORKER_ZERO_TIMERS, Timer::stats()['num']);
    }

    /** @return array{Server, EventDispatcher} */
    private function startWorkerZero(): array
    {
        $workerDrain = new WorkerDrain($this->state, new DrainBudget(10), new EventDispatcher(), new NullLogger());
        $healthMonitor = $this->healthMonitor();
        $memoryTable = new WorkerMemoryTable();
        $memoryTable->boot();
        $memoryReporter = new WorkerMemoryReporter($memoryTable);
        $watchdog = new CoroutineWatchdogWorkerStartHandler(new CoroutineWatchdog(), new Swoole());

        $dispatcher = new EventDispatcher();
        foreach ([$workerDrain, $healthMonitor, $memoryReporter, $this->qol()] as $subscriber) {
            $dispatcher->addSubscriber($subscriber);
        }
        $dispatcher->addSubscriber(new DrainWorkerExitSubscriber($this->state, $workerDrain, $healthMonitor, $memoryReporter, $watchdog));

        // A constructed server also keeps the timers' event loop from running when the test ends.
        $server = new Server('127.0.0.1', 0);
        $server->worker_id = 0;
        $dispatcher->dispatch(new WorkerStartedEvent($server, 0), WorkerStartedEvent::NAME);
        $watchdog->handle($server, 0);

        return [$server, $dispatcher];
    }

    private function qol(): QoLWorkerStartupSubscriber
    {
        $fingerprint = $this->createStub(EncoderProfileFingerprintPortInterface::class);
        $fingerprint->method('getName')->willReturn('software');
        $governor = new StreamGovernor(new LearningModel(), $this->createStub(QualityLadderPortInterface::class), new InMemoryAlgorithmProfileStore());
        $sampler = new CpuGpuSampler(new NullLogger());
        $sampler->boot();
        $monitor = new MidStreamMonitor($governor, $sampler, new NullLogger());
        $persister = new LearningDataPersister($governor, $fingerprint, new NullLogger(), $this->directory, new JsonEncoder());

        return new QoLWorkerStartupSubscriber(new ServiceLocator([
            CpuGpuSampler::class => static fn (): CpuGpuSampler => $sampler,
            MidStreamMonitor::class => static fn (): MidStreamMonitor => $monitor,
            LearningDataPersister::class => static fn (): LearningDataPersister => $persister,
            StreamGovernor::class => static fn (): StreamGovernor => $governor,
            EncoderProfileFingerprintPortInterface::class => static fn (): EncoderProfileFingerprintPortInterface => $fingerprint,
        ]), new NullLogger(), $this->state);
    }

    private function healthMonitor(): HealthMonitorSubscriber
    {
        $redis = $this->createStub(RedisClientFactory::class);
        $checks = new HealthCheckService(
            connection: $this->createStub(Connection::class),
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
            new MockClock(),
            new NullLogger(),
        );

        return new HealthMonitorSubscriber($alerts, new NullLogger(), 60, drain: $this->state);
    }
}
