<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure;

use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Catalog\Infrastructure\VideoService;
use App\Library\Application\Port\LibraryMediaFileResolverInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VideoServiceTest extends TestCase
{
    public function testDeniedLibraryScopeDoesNotResolveAFile(): void
    {
        $videos = $this->createMock(VideoRepositoryInterface::class);
        $files = $this->createMock(LibraryMediaFileResolverInterface::class);
        $id = new PublicId();
        $scope = LibraryReadScope::none();
        $videos->expects(self::once())->method('getLibraryIdsByPublicId')->with($id, $scope)->willReturn([]);
        $videos->expects(self::never())->method('findByPublicId');
        $files->expects(self::never())->method('resolve');

        self::assertNull((new VideoService($videos, $files))->getSourceMetadata($id, $scope));
    }

    public function testSourceRequiresConfinementWithinOneOfItsScopedLibraries(): void
    {
        $videos = $this->createStub(VideoRepositoryInterface::class);
        $files = $this->createMock(LibraryMediaFileResolverInterface::class);
        $video = Video::create('/outside/video.mkv', 'source-hash');
        $library = new Uuid();
        $videos->method('getLibraryIdsByPublicId')->willReturn([$library]);
        $videos->method('findByPublicId')->willReturn($video);
        $files->expects(self::once())->method('resolve')->with($library, '/outside/video.mkv')
            ->willThrowException(new InvalidArgumentException('Path is outside library.'));

        self::assertNull((new VideoService($videos, $files))->getSourceMetadata($video->getPublicId(), LibraryReadScope::restricted([$library])));
    }

    public function testReturnsTrustedMetadataAndResolvedPathFromAccessibleLibrary(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'video-source-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, 'source');
            $videos = $this->createStub(VideoRepositoryInterface::class);
            $files = $this->createMock(LibraryMediaFileResolverInterface::class);
            $video = Video::create('/catalog/video.mkv', 'source-hash');
            $video->updateProbe([
                'containerFormat' => 'matroska,webm', 'videoCodec' => 'h264', 'videoStreamIndex' => 3,
                'width' => 1920, 'height' => 1080, 'framerate' => 23.976, 'duration' => 12.5,
                'audioStreams' => [['streamIndex' => 5, 'codec' => 'aac', 'channels' => 2]],
                'subtitleStreams' => [['index' => 6, 'codec' => 'subrip']],
                'pixFmt' => 'yuv420p10le', 'colorSpace' => ['primaries' => 'bt2020', 'transfer' => 'smpte2084', 'matrix' => 'bt2020nc'],
                'colorRange' => 'tv', 'sourceTimestampOrigin' => 2.125,
            ]);
            $first = new Uuid();
            $second = new Uuid();
            $videos->method('getLibraryIdsByPublicId')->willReturn([$first, $second]);
            $videos->method('findByPublicId')->willReturn($video);
            $files->expects(self::exactly(2))->method('resolve')->willReturnCallback(
                static function (Uuid $library, string $source) use ($first, $path): string {
                    self::assertSame('/catalog/video.mkv', $source);
                    if ($library->equals($first)) {
                        throw new InvalidArgumentException('Outside first library.');
                    }
                    return $path;
                },
            );

            $source = (new VideoService($videos, $files))->getSourceMetadata($video->getPublicId(), LibraryReadScope::restricted([$first, $second]));
            self::assertNotNull($source);
            self::assertSame($path, $source->filePath);
            self::assertSame(6, $source->size);
            self::assertSame('matroska,webm', $source->containerFormat);
            self::assertSame(3, $source->videoStreamIndex);
            self::assertSame(5, $source->audioStreams[0]['streamIndex']);
            self::assertSame(12.5, $source->duration);
            self::assertSame('yuv420p10le', $source->pixFmt);
            self::assertSame('smpte2084', $source->colorSpace['transfer']);
            self::assertSame('tv', $source->colorRange);
            self::assertSame(2.125, $source->sourceTimestampOrigin);
            self::assertTrue($source->hasSubtitles);
        } finally {
            unlink($path);
        }
    }
}
