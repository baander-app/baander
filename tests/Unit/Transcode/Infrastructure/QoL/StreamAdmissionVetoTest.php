<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\QoL;

use App\Tests\Fixtures\QoL\InMemoryAlgorithmProfileStore;
use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Domain\Exception\StreamBudgetExhausted;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Domain\ValueObject\UtilizationSample;
use App\QoL\Infrastructure\EventListener\StreamBudgetExceptionListener;
use App\QoL\Infrastructure\StreamAdmissionService;
use App\QoL\Infrastructure\Swoole\CpuGpuSampler;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\CommandHandler\CreateTranscodeSessionHandler;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeLoopLeaseInterface;
use App\Transcode\Application\Port\TranscodeLoopLockInterface;
use App\Transcode\Application\Port\TranscodeLoopStarterInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Event\TranscodeSessionAttached;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\Repository\TranscodeSessionRepositoryInterface;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\HardwareCapabilitiesProber;
use App\Transcode\Infrastructure\QoL\StreamAdmissionListener;
use App\Transcode\Infrastructure\Swoole\GracefulRestartHandler;
use App\Transcode\Infrastructure\Swoole\JobStatePersister;
use App\Transcode\Infrastructure\Swoole\TranscodeProcessPool;
use App\Transcode\Infrastructure\Transcode\QualityLadderPort;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class StreamAdmissionVetoTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-admission-veto-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testOverBudgetAttachVetoesSessionStartupWithUnchangedResponse(): void
    {
        $governor = $this->activeGovernor([100.0, 0.0, 0.0, 0.0]);
        $governor->allocateStream(Uuid::generate(), '1080p', 12.34567);
        $userId = Uuid::generate();
        $videoId = Uuid::generate();
        $tier = QualityTier::p720();
        $audio = AudioProfile::streamingStereo();
        $job = TranscodeJob::create($videoId, $tier, '/tmp/baander-transcode');
        $session = TranscodeSession::create($userId, $job->getId(), $videoId, $audio);
        $storage = $this->createStub(TranscodeStoragePortInterface::class);
        $storage->method('resolveJobDirectory')->willReturn('/tmp/baander-transcode');
        $jobs = $this->createStub(TranscodeJobPortInterface::class);
        $jobs->method('getOrCreateJob')->willReturn($job);
        $sessions = $this->createStub(TranscodeSessionPortInterface::class);
        $sessions->method('findByJob')->willReturn([]);
        $sessions->method('createSession')->willReturn($session);
        $lease = $this->createMock(TranscodeLoopLeaseInterface::class);
        $lease->expects(self::once())->method('release');
        $lock = $this->createStub(TranscodeLoopLockInterface::class);
        $lock->method('acquire')->willReturn($lease);
        $starter = $this->createMock(TranscodeLoopStarterInterface::class);
        $starter->expects(self::never())->method('start');
        $handler = new CreateTranscodeSessionHandler($jobs, $sessions, $storage, $this->dispatcher($governor), $lock, $starter, $this->createStub(SleeperInterface::class));

        $veto = $this->expectVeto(static fn (): TranscodeSession => $handler(
            new CreateTranscodeSessionCommand($userId, $videoId, $tier, $audio),
        ));

        self::assertSame(1, $governor->getActiveStreamCount());
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
            $veto,
        );
        (new StreamBudgetExceptionListener())($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            [
                'error' => 'stream_budget_exhausted',
                'active_streams' => 1,
                'budget_used' => 0.1235,
                'requested_tier' => '720p',
                'message' => 'Stream budget exhausted: 1 active streams using 12% capacity, tier "720p" does not fit.',
            ],
            json_decode($response->getContent() ?: '', true, 32, JSON_THROW_ON_ERROR),
        );
    }

    public function testOverBudgetRestartResumeIsVetoedBeforeTheLoopStarts(): void
    {
        $governor = $this->activeGovernor([100.0, 0.0, 0.0, 0.0]);
        $logger = new NullLogger();
        $json = new JsonEncoder();
        $job = TranscodeJob::create(Uuid::generate(), QualityTier::p720(), '/tmp/baander-transcode');
        $job->markInProgress();
        $session = TranscodeSession::create(Uuid::generate(), $job->getId(), $job->getVideoId(), AudioProfile::streamingStereo());
        $persister = new JobStatePersister($this->createStub(TranscodeStoragePortInterface::class), $logger, $this->directory, $json);
        $persister->persist($job);
        $jobs = $this->createStub(TranscodeJobPortInterface::class);
        $jobs->method('findByUuid')->willReturn($job);
        $sessions = $this->createStub(TranscodeSessionRepositoryInterface::class);
        $sessions->method('findByJob')->willReturn([$session]);
        $lease = $this->createMock(TranscodeLoopLeaseInterface::class);
        $lease->expects(self::once())->method('release');
        $lock = $this->createStub(TranscodeLoopLockInterface::class);
        $lock->method('acquire')->willReturn($lease);
        $starter = $this->createMock(TranscodeLoopStarterInterface::class);
        $starter->expects(self::never())->method('start');
        $pool = new TranscodeProcessPool(new CpuProcessPool([], 1, $logger), $logger, $json, EncoderProfile::software());
        $handler = new GracefulRestartHandler(
            $jobs,
            $sessions,
            $persister,
            $pool,
            $this->dispatcher($governor),
            $lock,
            $starter,
            $logger,
        );

        $veto = $this->expectVeto(static fn (): int => $handler->resumePersistedJobs());

        self::assertSame('720p', $veto->requestedTier);
        self::assertSame(0, $governor->getActiveStreamCount());
    }

    public function testAttachWithinBudgetAdmitsTheSessionAtTheRequestedTier(): void
    {
        // Cost = 10 + 21.6 * (1080 / 2160) + 10 * (2.8 Mbit / 20 Mbit) - 5 (hardware) = 17.2
        $governor = $this->activeGovernor([10.0, 21.6, 10.0, -5.0]);
        $jobId = Uuid::generate();

        $this->dispatcher($governor)->dispatch($this->attached($jobId, '720p'));

        $streams = $governor->getActiveStreams();
        self::assertCount(1, $streams);
        self::assertTrue($streams[0]->jobId->equals($jobId));
        self::assertSame('720p', $streams[0]->qualityTier);
        self::assertEqualsWithDelta(17.2, $streams[0]->predictedCost, 0.0001);
    }

    public function testAttachWithoutTierIsNotAdmitted(): void
    {
        $governor = $this->activeGovernor([100.0, 0.0, 0.0, 0.0]);

        $this->dispatcher($governor)->dispatch($this->attached(Uuid::generate(), ''));

        self::assertSame(0, $governor->getActiveStreamCount());
    }

    /** @param callable(): mixed $startup */
    private function expectVeto(callable $startup): StreamBudgetExhausted
    {
        try {
            $startup();
        } catch (StreamBudgetExhausted $veto) {
            return $veto;
        }

        self::fail('Expected the stream budget to veto the session.');
    }

    private function attached(Uuid $jobId, string $tier): TranscodeSessionAttached
    {
        return new TranscodeSessionAttached(
            sessionId: Uuid::generate(),
            jobId: $jobId,
            userId: Uuid::generate(),
            qualityTier: $tier,
        );
    }

    private function dispatcher(StreamGovernor $governor): EventDispatcher
    {
        $prober = $this->createStub(HardwareCapabilitiesProber::class);
        $prober->method('getProfile')->willReturn(EncoderProfile::fromEncoderName('hevc_nvenc'));
        $fingerprint = $this->createStub(EncoderProfileFingerprintPortInterface::class);
        $fingerprint->method('getName')->willReturn('nvenc/hevc_nvenc');
        $admission = new StreamAdmissionService(
            $governor,
            new CpuGpuSampler(new NullLogger()),
            new LearningDataPersister($governor, $fingerprint, new NullLogger(), $this->directory, new JsonEncoder()),
            new NullLogger(),
        );
        $listener = new StreamAdmissionListener($admission, $prober);
        $attribute = (new \ReflectionMethod($listener, '__invoke'))
            ->getAttributes(AsEventListener::class)[0]
            ->newInstance();
        self::assertSame(TranscodeSessionAttached::class, $attribute->event);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TranscodeSessionAttached::class, $listener, $attribute->priority);

        return $dispatcher;
    }

    /** @param array{float, float, float, float} $coefficients */
    private function activeGovernor(array $coefficients): StreamGovernor
    {
        $model = new LearningModel();
        for ($i = 0; $i < LearningModel::MIN_SAMPLES; $i++) {
            $model->addSample(new UtilizationSample(
                cpuPercent: 50.0,
                gpuPercent: 0.0,
                encodeFps: 30.0,
                sourceHeight: 1080,
                sourceCodec: 'h264',
                hardwareAccelerated: false,
                targetBitrate: 5_000_000,
                qualityTier: '1080p',
                activeStreams: 1,
            ));
        }
        $model->restoreState(['samples' => $model->getState()['samples'], 'coefficients' => $coefficients]);
        $governor = new StreamGovernor($model, new QualityLadderPort(), new InMemoryAlgorithmProfileStore());
        $governor->activate();

        return $governor;
    }
}
