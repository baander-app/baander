<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\QoL;

use App\QoL\Application\Port\EncoderProfileFingerprintPortInterface;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\Service\StreamGovernor;
use App\QoL\Infrastructure\StreamAdmissionService;
use App\QoL\Infrastructure\Swoole\CpuGpuSampler;
use App\QoL\Infrastructure\Swoole\LearningDataPersister;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Domain\Event\TranscodeJobCompleted;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\HardwareCapabilitiesProber;
use App\Transcode\Infrastructure\QoL\StreamCompletionListener;
use App\Transcode\Infrastructure\Transcode\QualityLadderPort;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class StreamCompletionListenerTest extends TestCase
{
    private string $directory;
    private StreamGovernor $governor;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-completion-' . bin2hex(random_bytes(8));
        $this->governor = new StreamGovernor(new LearningModel(), new QualityLadderPort());
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

    public function testCompletionRecordsLearningSampleAndReleasesTheStream(): void
    {
        $job = TranscodeJob::create(Uuid::generate(), QualityTier::p720(), '/tmp/baander-transcode');
        $job->updateProbeData(['videoStreams' => [['height' => 2160, 'codecName' => 'hevc']]]);
        $this->governor->allocateStream($job->getId(), '720p', 30.0);
        $this->governor->allocateStream(Uuid::generate(), '1080p', 20.0);

        $this->listener($job)($this->completed($job->getId()));

        self::assertSame(1, $this->governor->getActiveStreamCount());
        self::assertSame([[
            'cpu_percent' => 42.5,
            'gpu_percent' => 7.0,
            'encode_fps' => 0.0,
            'source_height' => 2160,
            'source_codec' => 'hevc',
            'hardware_accelerated' => true,
            'target_bitrate' => 2_800_000,
            'quality_tier' => '720p',
            'active_streams' => 2,
        ]], $this->recordedSamples());
    }

    public function testCompletionWithoutProbeDataRecordsUnknownSourceAndReleasesTheStream(): void
    {
        $job = TranscodeJob::create(Uuid::generate(), QualityTier::p1080(), '/tmp/baander-transcode');
        $this->governor->allocateStream($job->getId(), '1080p', 30.0);

        $this->listener($job)($this->completed($job->getId()));

        self::assertSame(0, $this->governor->getActiveStreamCount());
        $samples = $this->recordedSamples();
        self::assertCount(1, $samples);
        self::assertSame(0, $samples[0]['source_height']);
        self::assertSame('', $samples[0]['source_codec']);
        self::assertSame(5_000_000, $samples[0]['target_bitrate']);
        self::assertSame('1080p', $samples[0]['quality_tier']);
    }

    public function testCompletionOfUnknownJobLogsWarningAndRecordsNothing(): void
    {
        $jobId = Uuid::generate();
        $this->governor->allocateStream($jobId, '720p', 30.0);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::anything(),
            ['jobId' => $jobId->toString()],
        );

        $this->listener(null, $logger)($this->completed($jobId));

        self::assertSame([], $this->recordedSamples());
        self::assertSame(1, $this->governor->getActiveStreamCount());
    }

    private function completed(Uuid $jobId): TranscodeJobCompleted
    {
        return new TranscodeJobCompleted($jobId, Uuid::generate(), '720p', 12);
    }

    /** @return list<array<string, mixed>> */
    private function recordedSamples(): array
    {
        return array_map(static function (array $sample): array {
            unset($sample['measured_at']);

            return $sample;
        }, $this->governor->getModel()->getState()['samples']);
    }

    private function listener(?TranscodeJob $job, ?LoggerInterface $logger = null): StreamCompletionListener
    {
        $jobs = $this->createStub(TranscodeJobPortInterface::class);
        $jobs->method('findByUuid')->willReturn($job);
        $prober = $this->createStub(HardwareCapabilitiesProber::class);
        $prober->method('getProfile')->willReturn(EncoderProfile::fromEncoderName('hevc_nvenc'));
        $sampler = new CpuGpuSampler(new NullLogger());
        $sampler->boot();
        $table = (new \ReflectionProperty($sampler, 'table'))->getValue($sampler);
        self::assertInstanceOf(\Swoole\Table::class, $table);
        $table->set('__latest', ['cpu_percent' => 42.5, 'gpu_percent' => 7.0, 'timestamp' => time()]);
        $fingerprint = $this->createStub(EncoderProfileFingerprintPortInterface::class);
        $fingerprint->method('getName')->willReturn('nvenc/hevc_nvenc');
        $persister = new LearningDataPersister($this->governor, $fingerprint, new NullLogger(), $this->directory, new JsonEncoder());

        return new StreamCompletionListener(
            new StreamAdmissionService($this->governor, $sampler, $persister, new NullLogger()),
            $jobs,
            $prober,
            $logger ?? new NullLogger(),
        );
    }
}
