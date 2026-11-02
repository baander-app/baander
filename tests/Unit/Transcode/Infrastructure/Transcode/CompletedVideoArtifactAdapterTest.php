<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Transcode;

use App\Catalog\Application\Port\VideoPortInterface;
use App\Catalog\Domain\Model\Video;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Support\PublishedPresentationFixture;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\PresentationSelection;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Storage\TranscodeFileStorage;
use App\Transcode\Infrastructure\Transcode\CompletedVideoArtifactAdapter;
use PHPUnit\Framework\TestCase;

final class CompletedVideoArtifactAdapterTest extends TestCase
{
    public function testOnlyCompleteCurrentSourceGenerationsCanBeReused(): void
    {
        $root = sys_get_temp_dir() . '/baander-copy-artifact-' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            file_put_contents($root . '/source.mkv', 'source');
            $video = Video::create($root . '/source.mkv', 'source-hash');
            $video->updateProbe(['subtitleStreams' => []]);
            $owner = Uuid::generate();
            $selection = new PresentationSelection('copy', 'source-hash', 3, null, 'mp4');
            $job = TranscodeJob::createCopy($video->getId(), $root, $owner, $selection, 320, 180);
            $storage = new TranscodeFileStorage(new SegmentFileResolver($root));
            $scoped = $storage->forGeneration($video->getId(), 'copy', 1, $job->getId());
            $directory = $scoped->resolveJobDirectory($video->getId(), 'copy');
            mkdir($directory, 0700, true);
            PublishedPresentationFixture::write($directory);
            file_put_contents($directory . '/generation.json', json_encode(['complete' => true, 'generation' => 1, 'startTime' => 0,
                'presentationSelection' => $selection->jsonSerialize(), 'videoCodec' => 'h264',
                'sourceSize' => 6, 'sourceModified' => filemtime($video->getPath()), 'bitDepth' => 8, 'hdr' => false, 'pixelFormat' => 'yuv420p'], JSON_THROW_ON_ERROR));
            $videos = $this->createStub(VideoPortInterface::class);
            $videos->method('findByUuid')->willReturn($video);
            $jobs = $this->createMock(TranscodeJobRepositoryInterface::class);
            $jobs->method('findActiveByOwnerAndVideo')->willReturn([$job]);
            $jobs->expects(self::never())->method('save');
            $runtime = $this->createMock(TranscodeExecutionOwnershipPort::class);
            $runtime->method('find')->willReturn(new TranscodeRuntimeSnapshot($job->getId(), Uuid::generate(), Uuid::generate()->toString(),
                new DesiredTranscodeState(AudioProfile::streamingStereo(), presentationSelection: $selection), 1, 1, 1, 'complete', $directory, null));
            $runtime->expects(self::never())->method('admit');
            $lookup = new CompletedVideoArtifactAdapter($jobs, $videos, $runtime, $storage, new PublishedPresentationReader());
            $complete = $lookup->findComplete($owner, $video->getId(), 'source-hash', 3, null);
            self::assertCount(1, $complete);
            self::assertSame('hls', $complete[0]->transport);
            self::assertSame([], $lookup->findComplete($owner, $video->getId(), 'changed', 3, null));
            self::assertSame([], $lookup->findComplete($owner, $video->getId(), 'source-hash', 4, null));
            $video->updateProbe(['subtitleStreams' => [['codec' => 'subrip']]]);
            self::assertSame([], $lookup->findComplete($owner, $video->getId(), 'source-hash', 3, null));
            $video->updateProbe(['subtitleStreams' => []]);
            unlink($directory . '/track-0-seg-00001.m4s');
            self::assertSame([], $lookup->findComplete($owner, $video->getId(), 'source-hash', 3, null));
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    }
}
