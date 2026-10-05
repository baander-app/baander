<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Infrastructure\Swoole;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Domain\Model\GovernorState;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Domain\ValueObject\AlgorithmProfile;
use App\QoL\Domain\ValueObject\UtilizationSample;
use App\QoL\Infrastructure\Swoole\CpuGpuSampler;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\QoL\Infrastructure\Swoole\MidStreamMonitor;
use App\QoL\Infrastructure\Swoole\QoLWorkerStartupSubscriber;
use App\Shared\Infrastructure\Swoole\SwooleWorkerEventBuffer;
use App\Shared\Infrastructure\Swoole\SwooleWorkerEventSubscriber;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Transcode\Application\Port\QualityLadderPortInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swoole\Server;
use Swoole\Timer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class QoLWorkerStartupSubscriberTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-qol-startup-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory));
    }

    protected function tearDown(): void
    {
        Timer::clearAll();
        $file = $this->directory . '/governor_state.json';
        if (is_file($file)) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testOtherWorkersDoNotResolveQoLServices(): void
    {
        $locator = $this->createMock(ContainerInterface::class);
        $locator->expects(self::never())->method('get');
        $locator->expects(self::never())->method('has');
        $subscriber = new QoLWorkerStartupSubscriber($locator, new NullLogger());

        $subscriber->onWorkerStarted(new WorkerStartedEvent(new Server('127.0.0.1', 0), 1));

        self::assertSame(0, Timer::stats()['num']);
    }

    public function testMatchingProfileRestoresStateAndStartsBothTimers(): void
    {
        [$subscriber, $governor, $persister] = $this->fixture();
        $governor->importState(['state' => 'active', 'profile' => 'aggressive']);
        $persister->persist();
        $governor->resetLearning();
        $governor->setProfile(AlgorithmProfile::Balanced);

        $subscriber->onWorkerStarted(new WorkerStartedEvent(new Server('127.0.0.1', 0), 0));

        self::assertSame(GovernorState::Active, $governor->getState());
        self::assertSame('aggressive', $governor->getProfile()->value);
        self::assertFileExists($this->directory . '/governor_state.json');
        self::assertSame(2, Timer::stats()['num']);
    }

    public function testChangedProfileResetsLearningAndRemovesStaleState(): void
    {
        [$subscriber, $governor] = $this->fixture();
        $governor->getModel()->addSample(new UtilizationSample(10.0, 0.0, 30.0, 1080, 'h264', false, 1000000, 'high', 1));
        $governor->importState(['state' => 'active']);
        file_put_contents($this->directory . '/governor_state.json', json_encode([
            'encoder_profile' => 'previous-encoder',
            'governor' => ['state' => 'active', 'profile' => 'aggressive'],
        ], JSON_THROW_ON_ERROR));

        $subscriber->onWorkerStarted(new WorkerStartedEvent(new Server('127.0.0.1', 0), 0));

        self::assertSame(GovernorState::Learning, $governor->getState());
        self::assertSame(0, $governor->getModel()->sampleCount());
        self::assertFileDoesNotExist($this->directory . '/governor_state.json');
        self::assertSame(2, Timer::stats()['num']);
    }

    public function testMissingSavedProfileRetainsExistingRestorationBehavior(): void
    {
        [$subscriber, $governor] = $this->fixture();
        file_put_contents($this->directory . '/governor_state.json', '{"governor":{"state":"active"}}');

        $subscriber->onWorkerStarted(new WorkerStartedEvent(new Server('127.0.0.1', 0), 0));

        self::assertSame(GovernorState::Active, $governor->getState());
    }

    public function testFirstStartupStartsTimersWithoutPersistedState(): void
    {
        [$subscriber, $governor] = $this->fixture();

        $subscriber->onWorkerStarted(new WorkerStartedEvent(new Server('127.0.0.1', 0), 0));

        self::assertSame(GovernorState::Learning, $governor->getState());
        self::assertFileDoesNotExist($this->directory . '/governor_state.json');
        self::assertSame(2, Timer::stats()['num']);
    }

    /** @return iterable<string, array{bool}> */
    public static function failureSources(): iterable
    {
        yield 'fingerprint unavailable' => [false];
        yield 'corrupt saved state' => [true];
    }

    #[DataProvider('failureSources')]
    public function testFailureIsLoggedWithoutPreventingSharedWorkerSetup(bool $corruptState): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'QoL governor startup failed',
            self::callback(static fn (array $context): bool => $context['exception'] instanceof \Throwable),
        );
        [$qol] = $this->fixture($logger, !$corruptState);
        if ($corruptState) {
            file_put_contents($this->directory . '/governor_state.json', 'broken json');
        }
        $registry = WebSocketConnectionRegistry::create(16, 16);
        $buffer = new SwooleWorkerEventBuffer();
        $shared = new SwooleWorkerEventSubscriber($buffer, webSocketRegistry: $registry);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($qol);
        $dispatcher->addSubscriber($shared);
        $server = new Server('127.0.0.1', 0);
        $server->worker_id = 7;

        $dispatcher->dispatch(new WorkerStartedEvent($server, 0), WorkerStartedEvent::NAME);

        self::assertSame(7, $registry->getWorkerId());
        self::assertSame(2, Timer::stats()['num']);
    }

    /** @return array{QoLWorkerStartupSubscriber, StreamGovernor, LearningDataPersister} */
    private function fixture(?LoggerInterface $logger = null, bool $failFingerprint = false): array
    {
        $fingerprint = $this->createStub(EncoderProfileFingerprintPortInterface::class);
        if ($failFingerprint) {
            $fingerprint->method('getName')->willThrowException(new \RuntimeException('encoder profile unavailable'));
        } else {
            $fingerprint->method('getName')->willReturn('software');
        }
        $governor = new StreamGovernor(new LearningModel(), $this->createStub(QualityLadderPortInterface::class));
        $persister = new LearningDataPersister($governor, $fingerprint, new NullLogger(), $this->directory, new JsonEncoder());
        $sampler = new CpuGpuSampler(new NullLogger());
        $sampler->boot();
        $monitor = new MidStreamMonitor($governor, $sampler, new NullLogger());
        $locator = new ServiceLocator([
            CpuGpuSampler::class => static fn (): CpuGpuSampler => $sampler,
            MidStreamMonitor::class => static fn (): MidStreamMonitor => $monitor,
            LearningDataPersister::class => static fn (): LearningDataPersister => $persister,
            StreamGovernor::class => static fn (): StreamGovernor => $governor,
            EncoderProfileFingerprintPortInterface::class => static fn (): EncoderProfileFingerprintPortInterface => $fingerprint,
        ]);

        return [new QoLWorkerStartupSubscriber($locator, $logger ?? new NullLogger()), $governor, $persister];
    }
}
