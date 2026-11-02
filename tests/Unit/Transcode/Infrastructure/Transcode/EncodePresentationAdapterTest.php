<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Transcode;

use App\Catalog\Application\Port\VideoPortInterface;
use App\Catalog\Domain\Model\Video;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\FFmpeg\WorkerEncoderCapabilitiesSnapshotStore;
use App\Transcode\Infrastructure\Transcode\EncodePresentationAdapter;
use PHPUnit\Framework\TestCase;

final class EncodePresentationAdapterTest extends TestCase
{
    private string $snapshotPath;

    protected function setUp(): void
    {
        $this->snapshotPath = sys_get_temp_dir() . '/encode-presentation-' . bin2hex(random_bytes(8)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->snapshotPath)) {
            unlink($this->snapshotPath);
        }
    }

    public function testClientIdentityAndWorkerObservationDoNotChangeContentIdentity(): void
    {
        $video = $this->video();
        $snapshots = new WorkerEncoderCapabilitiesSnapshotStore($this->snapshotPath);
        $snapshots->publish(EncoderProfile::software(), 1.0, 'worker-one');
        $adapter = $this->adapter($video, $snapshots);
        $command = fn(string $requestId) => new CreateTranscodeSessionCommand(Uuid::generate(), $video->getId(), QualityTier::p720(), AudioProfile::streamingStereo(), requestId: $requestId);
        $first = $adapter->select($command('client-one'));
        $snapshots->publish(EncoderProfile::software(), 1.0, 'worker-two');
        self::assertSame($first->canonicalKey(), $adapter->select($command('client-two'))->canonicalKey());
        $snapshots->publish(EncoderProfile::software('libx264'), 1.0, 'worker-two');
        self::assertNotSame($first->canonicalKey(), $adapter->select($command('client-two'))->canonicalKey());
    }

    public function testMissingWorkerObservationRejectsUnverifiablePresentation(): void
    {
        $video = $this->video();
        $adapter = $this->adapter($video, new WorkerEncoderCapabilitiesSnapshotStore($this->snapshotPath));
        $this->expectException(TranscodeStartupUnavailableException::class);
        $adapter->select(new CreateTranscodeSessionCommand(Uuid::generate(), $video->getId(), QualityTier::p720(), AudioProfile::streamingStereo()));
    }

    public function testMissingScanMetadataRejectsUnverifiablePresentation(): void
    {
        $video = Video::create('/media/source.mkv', 'source-hash');
        $snapshots = new WorkerEncoderCapabilitiesSnapshotStore($this->snapshotPath);
        $snapshots->publish(EncoderProfile::software(), 1.0, 'worker');
        $this->expectException(TranscodeStartupUnavailableException::class);
        $this->adapter($video, $snapshots)->select(new CreateTranscodeSessionCommand(Uuid::generate(), $video->getId(), QualityTier::p720(), AudioProfile::streamingStereo()));
    }

    private function adapter(Video $video, WorkerEncoderCapabilitiesSnapshotStore $snapshots): EncodePresentationAdapter
    {
        $videos = $this->createStub(VideoPortInterface::class);
        $videos->method('findByUuid')->willReturn($video);
        return new EncodePresentationAdapter($videos, $snapshots, new MediaExecutionPolicy(new DeploymentProfile('full')), $this->createStub(FFmpegPortInterface::class), $this->createStub(TranscodeStoragePortInterface::class));
    }

    private function video(): Video
    {
        $video = Video::create('/media/source.mkv', 'source-hash');
        $video->updateProbe(VideoProbeResult::fromProbeOutput(['streams' => [
            ['codec_type' => 'video', 'index' => 3, 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080, 'avg_frame_rate' => '24/1'],
            ['codec_type' => 'audio', 'index' => 7, 'codec_name' => 'aac', 'channels' => 2, 'sample_rate' => 48000, 'disposition' => ['default' => 1]],
        ], 'format' => ['duration' => '12']])->jsonSerialize());
        return $video;
    }
}
