<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Transcode;

use App\Catalog\Application\Port\VideoPortInterface;
use App\Catalog\Domain\Model\Video;
use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Support\PublishedPresentationFixture;
use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\ValueObject\PresentationSelection;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use App\Transcode\Infrastructure\HLS\ManifestGenerator;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Storage\TranscodeFileStorage;
use App\Transcode\Infrastructure\Transcode\CompletedVideoDeliveryAdapter;
use PHPUnit\Framework\TestCase;

final class CompletedVideoDeliveryAdapterTest extends TestCase
{
    public function testSegmentRequestsValidateTheRequestedFileWithoutScanningOtherSegments(): void
    {
        $root = sys_get_temp_dir() . '/baander-segment-delivery-' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            $video = Video::create($root . '/source.mkv', 'source');
            $selection = new PresentationSelection('copy', 'source', 0, 1, 'mp4');
            $job = TranscodeJob::createCopy($video->getId(), $root, Uuid::generate(), $selection, 320, 180);
            $storage = new TranscodeFileStorage(new SegmentFileResolver($root));
            $directory = $storage->forGeneration($video->getId(), 'copy', 1, $job->getId())->resolveJobDirectory($video->getId(), 'copy');
            mkdir($directory, 0700, true);
            PublishedPresentationFixture::write($directory, 3, true);
            $metadata = ['complete' => true, 'generation' => 1, 'startTime' => 0, 'presentationSelection' => $selection->jsonSerialize()];
            file_put_contents($directory . '/generation.json', json_encode($metadata, JSON_THROW_ON_ERROR));
            $jobs = $this->createStub(TranscodeJobRepositoryInterface::class);
            $jobs->method('findByPublicId')->willReturn($job);
            $videos = $this->createStub(VideoPortInterface::class);
            $videos->method('findByUuid')->willReturn($video);
            $authorization = new class { public bool $denied = false; };
            $access = $this->createMock(PlaybackMediaAccessInterface::class);
            $access->expects(self::atLeast(10))->method('assertVideoAccess')->with($job->getOwnerUserId(), $video->getPublicId())
                ->willReturnCallback(static function () use ($authorization): void { if ($authorization->denied) { throw new \RuntimeException('Access revoked'); } });
            $delivery = new CompletedVideoDeliveryAdapter($jobs, $videos, $access, $storage, new PublishedPresentationReader(), new ManifestGenerator(), new HmacStreamAuthAdapter('requested-segment-proof'));
            $expiry = time() + 60;
            self::assertTrue($delivery->grant($job->getPublicId(), 1, $expiry));
            $segment = $directory . '/track-0-seg-00001.m4s';
            file_put_contents($directory . '/track-1-seg-00003.m4s', 'corrupt unrelated segment');
            self::assertSame($segment, $delivery->path($job->getPublicId(), 1, MediaTrack::Video, 0, $expiry));
            $init = $directory . '/init-0.mp4';
            $initBytes = file_get_contents($init);
            self::assertSame($init, $delivery->path($job->getPublicId(), 1, MediaTrack::Video, null, $expiry));
            file_put_contents($init, pack('Na4', 8, 'ftyp'));
            self::assertNull($delivery->path($job->getPublicId(), 1, MediaTrack::Video, null, $expiry));
            file_put_contents($init, $initBytes);
            rename($init, $init . '.closed');
            symlink($init . '.closed', $init);
            self::assertNull($delivery->path($job->getPublicId(), 1, MediaTrack::Video, null, $expiry));
            unlink($init);
            rename($init . '.closed', $init);
            self::assertNull($delivery->master($job->getPublicId(), 1, $expiry));
            self::assertNull($delivery->playlist($job->getPublicId(), 1, MediaTrack::Audio, $expiry));
            self::assertFalse($delivery->grant($job->getPublicId(), 1, $expiry));
            foreach (['', 'moof', pack('Na4', 8, 'moof'), pack('Na4', 8, 'moof') . pack('Na4', 100, 'mdat')] as $contents) {
                file_put_contents($segment, $contents);
                self::assertNull($delivery->path($job->getPublicId(), 1, MediaTrack::Video, 0, $expiry));
            }
            unlink($segment);
            self::assertNull($delivery->path($job->getPublicId(), 1, MediaTrack::Video, 0, $expiry));
            $closed = $directory . '/closed.m4s';
            file_put_contents($closed, PublishedPresentationFixture::fragment());
            symlink($closed, $segment);
            self::assertNull($delivery->path($job->getPublicId(), 1, MediaTrack::Video, 0, $expiry));
            unlink($segment);
            file_put_contents($segment, PublishedPresentationFixture::fragment());
            self::assertNull($delivery->path($job->getPublicId(), 1, MediaTrack::Video, 3, $expiry));
            self::assertNull($delivery->path($job->getPublicId(), 1, MediaTrack::Video, 0, time() - 1));
            $metadata['presentationSelection']['sourceHash'] = 'different';
            file_put_contents($directory . '/generation.json', json_encode($metadata, JSON_THROW_ON_ERROR));
            self::assertNull($delivery->path($job->getPublicId(), 1, MediaTrack::Video, 0, $expiry));
            $metadata['presentationSelection'] = $selection->jsonSerialize();
            file_put_contents($directory . '/generation.json', json_encode($metadata, JSON_THROW_ON_ERROR));
            self::assertNull($delivery->path($job->getPublicId(), 2, MediaTrack::Video, 0, $expiry));
            $leases = glob($root . '/.retention-*.lease');
            self::assertCount(1, $leases);
            file_put_contents($leases[0], (time() - 1) . "\n");
            self::assertNull($delivery->path($job->getPublicId(), 1, MediaTrack::Video, 0, $expiry));
            $authorization->denied = true;
            $this->expectExceptionMessage('Access revoked');
            $delivery->path($job->getPublicId(), 1, MediaTrack::Video, 0, $expiry);
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($root);
        }
    }

    public function testGrantedGenerationSurvivesProducerCancellationAndIsProtectedFromCleanup(): void
    {
        $root = sys_get_temp_dir() . '/baander-granted-video-' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            $video = Video::create($root . '/source.mkv', 'source');
            $selection = new PresentationSelection('copy', 'source', 0, null, 'mp4');
            $job = TranscodeJob::createCopy($video->getId(), $root, Uuid::generate(), $selection, 320, 180);
            $storage = new TranscodeFileStorage(new SegmentFileResolver($root));
            $directory = $storage->forGeneration($video->getId(), 'copy', 1, $job->getId())->resolveJobDirectory($video->getId(), 'copy');
            mkdir($directory, 0700, true);
            PublishedPresentationFixture::write($directory);
            $metadata = ['complete' => true, 'generation' => 1, 'startTime' => 0, 'presentationSelection' => $selection->jsonSerialize(), 'hlsTargetDuration' => 7];
            file_put_contents($directory . '/generation.json', json_encode($metadata, JSON_THROW_ON_ERROR));
            $jobs = $this->createStub(TranscodeJobRepositoryInterface::class);
            $jobs->method('findByPublicId')->willReturn($job);
            $videos = $this->createStub(VideoPortInterface::class);
            $videos->method('findByUuid')->willReturn($video);
            $authorization = new class { public bool $denied = false; };
            $access = $this->createMock(PlaybackMediaAccessInterface::class);
            $access->expects(self::atLeast(5))->method('assertVideoAccess')->with($job->getOwnerUserId(), $video->getPublicId())
                ->willReturnCallback(static function () use ($authorization): void { if ($authorization->denied) { throw new \RuntimeException('Access revoked'); } });
            $delivery = new CompletedVideoDeliveryAdapter($jobs, $videos, $access, $storage, new PublishedPresentationReader(), new ManifestGenerator(), new HmacStreamAuthAdapter('immutable-copy-proof'));
            $expiry = time() + 60;
            self::assertTrue($delivery->grant($job->getPublicId(), 1, $expiry));
            $job->getState()->status = TranscodeStatus::Cancelled;
            $job->getState()->outputDirectory = $root . '/new-generation';
            self::assertStringContainsString('/artifacts/', $delivery->master($job->getPublicId(), 1, $expiry));
            self::assertStringContainsString('#EXT-X-ENDLIST', $delivery->playlist($job->getPublicId(), 1, MediaTrack::Video, $expiry));
            self::assertSame($directory . '/track-0-seg-00001.m4s', $delivery->path($job->getPublicId(), 1, MediaTrack::Video, 0, $expiry));
            self::assertFalse($storage->deleteIfUnretained($root . '/' . $video->getId()->toString()));
            self::assertFileExists($directory . '/track-0-seg-00001.m4s');
            $metadata['complete'] = false;
            file_put_contents($directory . '/generation.json', json_encode($metadata, JSON_THROW_ON_ERROR));
            self::assertNull($delivery->master($job->getPublicId(), 1, $expiry));
            $authorization->denied = true;
            $this->expectExceptionMessage('Access revoked');
            $delivery->master($job->getPublicId(), 1, $expiry);
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    }
}
