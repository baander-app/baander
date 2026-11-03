<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Infrastructure;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Domain\Port\QualityLadderPortInterface;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Infrastructure\HostCpus;
use App\QoL\Infrastructure\StreamAdmissionService;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\QoL\InMemoryAlgorithmProfileStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class StreamAdmissionServiceTest extends TestCase
{
    private const int HOST_CPUS = 16;

    /** Heights and tiers the fixtures cycle through, so each encode kind's regression can train. */
    private const array SOURCES = [[720, '720p', 2_800_000], [1080, '1080p', 5_000_000], [1440, '1440p', 10_000_000], [2160, '4K', 20_000_000]];

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-admission-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testCompletionReleasesAllocationWithoutLearningFromTooLittleOutput(): void
    {
        [$service, $governor] = $this->service();
        $jobId = new Uuid();
        $governor->allocateStream($jobId, '1080p', 25.0);
        self::assertSame(1, $governor->getActiveStreamCount());

        $service->complete($jobId, '1080p', 5000000, 1080, 'h264', false, 3.0, 2.0);

        self::assertSame(0, $governor->getActiveStreamCount());
        self::assertSame(0, $governor->getModel()->sampleCount());
        self::assertFileDoesNotExist($this->directory . '/governor_state.json');
    }

    public function testCompletionLearnsTheStreamsCpuPerOutputSecondAgainstTheHostCpus(): void
    {
        [$service, $governor] = $this->service();
        $jobId = new Uuid();
        $governor->allocateStream($jobId, '1080p', 25.0);

        // 96 CPU seconds for 60 seconds of video: 1.6 CPUs of 16 keep up with playback.
        $service->complete($jobId, '1080p', 5000000, 1080, 'h264', false, 96.0, 60.0);

        self::assertSame(0, $governor->getActiveStreamCount());
        self::assertEqualsWithDelta(10.0, $governor->getModel()->averageCostForTier('1080p', false), 0.000001);
    }

    /**
     * Contention slows each encoder but leaves its CPU seconds per output second nearly
     * unchanged, so samples taken while eight encodes share the host must predict the
     * same cost as samples taken alone. The tolerance is one percentage point of the host.
     */
    public function testSamplesFromEightConcurrentEncodesPredictTheIsolatedCost(): void
    {
        [$isolated, $isolatedGovernor] = $this->service();
        [$concurrent, $concurrentGovernor] = $this->service();

        for ($i = 0; $i < LearningModel::MIN_SAMPLES; $i++) {
            [$height, $tier, $bitrate] = self::SOURCES[$i % count(self::SOURCES)];
            $cpu = self::cpuSecondsFor($height, $bitrate);

            $alone = new Uuid();
            $isolatedGovernor->allocateStream($alone, $tier, 10.0);
            $isolated->complete($alone, $tier, $bitrate, $height, 'h264', false, $cpu, 60.0);

            $batch = [];
            for ($stream = 0; $stream < 8; $stream++) {
                $batch[] = $jobId = new Uuid();
                $concurrentGovernor->allocateStream($jobId, $tier, 10.0);
            }
            self::assertSame(8, $concurrentGovernor->getActiveStreamCount());
            foreach ($batch as $stream => $jobId) {
                // Cache and memory contention adds up to two percent CPU per output second.
                $concurrent->complete($jobId, $tier, $bitrate, $height, 'h264', false, $cpu * (1.0 + 0.0025 * $stream), 60.0);
            }
        }

        foreach (self::SOURCES as [$height, , $bitrate]) {
            $alone = $isolatedGovernor->getModel()->predict($height, $bitrate, false);
            $shared = $concurrentGovernor->getModel()->predict($height, $bitrate, false);
            self::assertNotNull($alone);
            self::assertNotNull($shared);
            self::assertEqualsWithDelta($alone, $shared, 1.0);
        }
    }

    public function testHardwareStreamsLearnedCostDoesNotRaiseTheSoftwarePrediction(): void
    {
        [$service, $governor] = $this->service();
        $this->learn($service, $governor, hardwareAccelerated: false, cpuScale: 1.0);
        $software = $governor->getModel()->predict(1080, 5_000_000, false);
        $softwareAverage = $governor->getModel()->averageCostForTier('1080p', false);

        // A hardware encoder whose host CPU cost is three times the software one.
        $this->learn($service, $governor, hardwareAccelerated: true, cpuScale: 3.0);

        self::assertNotNull($software);
        self::assertNotNull($softwareAverage);
        self::assertEqualsWithDelta($software, $governor->getModel()->predict(1080, 5_000_000, false), 0.000001);
        self::assertEqualsWithDelta($softwareAverage, $governor->getModel()->averageCostForTier('1080p', false), 0.000001);
        self::assertGreaterThan($software, $governor->getModel()->predict(1080, 5_000_000, true));
    }

    public function testObservationRecordsTheRunningStreamsMeasuredCost(): void
    {
        [$service, $governor] = $this->service();
        $jobId = new Uuid();
        $governor->allocateStream($jobId, '1080p', 25.0);

        $service->observe($jobId, 1.0, 2.0);
        self::assertNull($governor->getActiveStreams()[0]->measuredCost);

        $service->observe($jobId, 48.0, 30.0);
        self::assertEqualsWithDelta(10.0, $governor->getActiveStreams()[0]->measuredCost, 0.000001);
        self::assertEqualsWithDelta(10.0, $governor->observedLoad(), 0.000001);
    }

    private function learn(StreamAdmissionService $service, StreamGovernor $governor, bool $hardwareAccelerated, float $cpuScale): void
    {
        for ($i = 0; $i < LearningModel::MIN_SAMPLES; $i++) {
            [$height, $tier, $bitrate] = self::SOURCES[$i % count(self::SOURCES)];
            $jobId = new Uuid();
            $governor->allocateStream($jobId, $tier, 10.0);
            $service->complete($jobId, $tier, $bitrate, $height, 'h264', $hardwareAccelerated,
                self::cpuSecondsFor($height, $bitrate) * $cpuScale, 60.0);
        }
    }

    /** CPU seconds a software encode spends on one minute of output: about 1.5 CPUs at 1080p, varied in height and bitrate. */
    private static function cpuSecondsFor(int $height, int $bitrate): float
    {
        return 60.0 * (0.4 + 0.8 * $height / 1080.0 + 0.1 * $bitrate / 5_000_000);
    }

    /** @return array{StreamAdmissionService, StreamGovernor} */
    private function service(): array
    {
        $governor = new StreamGovernor(new LearningModel(), $this->createStub(QualityLadderPortInterface::class), new InMemoryAlgorithmProfileStore());
        $fingerprint = $this->createStub(EncoderProfileFingerprintPortInterface::class);
        $fingerprint->method('getName')->willReturn('none/libx265');
        $persister = new LearningDataPersister($governor, $fingerprint, new NullLogger(), $this->directory, new JsonEncoder());

        return [new StreamAdmissionService($governor, $persister, new NullLogger(), new HostCpus(self::HOST_CPUS)), $governor];
    }
}
