<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure;

use App\Catalog\Domain\Model\Video;
use App\Catalog\Application\Port\VideoAvailabilityPortInterface;
use App\Catalog\Domain\ReadModel\VideoPlacementPath;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Catalog\Infrastructure\VideoService;
use App\Library\Application\Port\LibraryMediaFileResolverInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoServiceTest extends TestCase
{
    public function testDeniedLibraryScopeDoesNotResolveAFile(): void
    {
        $videos = $this->createMock(VideoRepositoryInterface::class);
        $files = $this->createMock(LibraryMediaFileResolverInterface::class);
        $id = new PublicId();
        $scope = LibraryReadScope::none();
        $videos->expects(self::once())->method('isVisibleByPublicId')->with($id, $scope)->willReturn(false);
        $videos->expects(self::never())->method('findByPublicId');
        $files->expects(self::never())->method('resolve');

        self::assertNull(new VideoService($videos, $files, $this->createStub(VideoAvailabilityPortInterface::class))->getSourceMetadata($id, $scope));
    }

    public function testSourceRequiresConfinementWithinOneOfItsScopedLibraries(): void
    {
        $videos = $this->createStub(VideoRepositoryInterface::class);
        $files = $this->createMock(LibraryMediaFileResolverInterface::class);
        $video = Video::create('/outside/video.mkv', 'source-hash');
        $library = new Uuid();
        $videos->method('isVisibleByPublicId')->willReturn(true);
        $videos->method('findByPublicId')->willReturn($video);
        $files->expects(self::once())->method('resolve')->with($library, '/outside/video.mkv')
            ->willThrowException(new InvalidArgumentException('Path is outside library.'));

        self::assertNull(new VideoService($videos, $files, $this->availability($video, [$library]))->getSourceMetadata($video->getPublicId(), LibraryReadScope::restricted([$library])));
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function servedTypes(): iterable
    {
        $matroska = 'matroska,webm';
        yield 'matroska probe' => ['/catalog/Amélie (2001).mkv', ['containerFormat' => $matroska], 'video/x-matroska'];
        yield 'webm member of the matroska family' => [
            '/catalog/clip.webm',
            ['containerFormat' => $matroska, 'videoCodec' => 'vp9', 'audioStreams' => [['codec' => 'opus']]],
            'video/webm',
        ];
        yield 'matroska named webm' => [
            '/catalog/clip.webm',
            ['containerFormat' => $matroska, 'videoCodec' => 'h264', 'audioStreams' => [['codec' => 'aac']]],
            'video/x-matroska',
        ];
        yield 'quicktime member of the mp4 family' => ['/catalog/clip.mov', ['containerFormat' => 'mov,mp4,m4a,3gp,3g2,mj2'], 'video/quicktime'];
        yield 'unprobed row typed by its extension' => ['/catalog/clip.mp4', [], 'video/mp4'];
        yield 'format of no video container' => ['/catalog/clip.mkv', ['containerFormat' => 'mpegts'], 'application/octet-stream'];
        yield 'extension of no video container' => ['/catalog/clip.xml', [], 'application/octet-stream'];
    }

    /** @param array<string, mixed> $probe */
    #[DataProvider('servedTypes')]
    public function testTheServedTypeComesFromTheContainerTableNeverFromTheBytes(string $catalogPath, array $probe, string $type): void
    {
        $path = tempnam(sys_get_temp_dir(), 'video-source-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, '<?xml version="1.0"?><x:script xmlns:x="http://www.w3.org/1999/xhtml">alert(document.domain)</x:script><!--' . str_repeat('padding ', 10240));
            $videos = $this->createStub(VideoRepositoryInterface::class);
            $files = $this->createStub(LibraryMediaFileResolverInterface::class);
            $video = Video::create($catalogPath, 'source-hash');
            if ($probe !== []) {
                $video->updateProbe($probe);
            }
            $library = new Uuid();
            $videos->method('isVisibleByPublicId')->willReturn(true);
            $videos->method('findByPublicId')->willReturn($video);
            $files->method('resolve')->willReturn($path);

            $source = new VideoService($videos, $files, $this->availability($video, [$library]))->getSourceMetadata($video->getPublicId(), LibraryReadScope::unrestricted());

            self::assertNotNull($source);
            self::assertSame($type, $source->mimeType);
            self::assertSame(basename($catalogPath), $source->filename);
        } finally {
            unlink($path);
        }
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
            $videos->method('isVisibleByPublicId')->willReturn(true);
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

            $source = new VideoService($videos, $files, $this->availability($video, [$first, $second]))->getSourceMetadata($video->getPublicId(), LibraryReadScope::restricted([$first, $second]));
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
    /** @param list<Uuid> $libraries */
    private function availability(Video $video, array $libraries): VideoAvailabilityPortInterface
    {
        $availability = $this->createStub(VideoAvailabilityPortInterface::class);
        $paths = array_map(
            static fn (Uuid $library): VideoPlacementPath => new VideoPlacementPath($video->getId(), $library, $video->getPath(), $video->getHash()),
            $libraries,
        );
        $availability->method('availablePaths')->willReturn([$video->getId()->toString() => $paths]);

        return $availability;
    }
}
