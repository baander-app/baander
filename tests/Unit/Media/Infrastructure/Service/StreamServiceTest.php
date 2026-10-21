<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure\Service;

use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Filesystem\LibraryMediaFileResolver;
use App\Library\Infrastructure\Scanner\DirectoryScanner;
use App\Media\Infrastructure\Service\StreamService;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StreamServiceTest extends TestCase
{
    private string $directory;
    private string $root;
    private string $source;
    private ?Library $library;
    private ?Song $song;
    private ?Uuid $ownerId;
    private StreamService $service;
    private PublicId $trackId;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-stream-source-' . bin2hex(random_bytes(8));
        $this->root = $this->directory . '/library';
        self::assertTrue(mkdir($this->root . '/album', 0700, true));
        self::assertTrue(mkdir($this->directory . '/library-other', 0700));
        $this->source = $this->root . '/album/track.flac';
        self::assertSame(10, file_put_contents($this->source, 'audio-data'));
        self::assertSame(7, file_put_contents($this->directory . '/outside.flac', 'outside'));
        self::assertSame(7, file_put_contents($this->directory . '/library-other/track.flac', 'outside'));
        $this->library = $this->libraryAt($this->root);
        $this->ownerId = $this->library->getId();
        $this->song = $this->songAt($this->source);
        $this->trackId = $this->song->getPublicId();

        $songs = $this->createStub(SongRepositoryInterface::class);
        $songs->method('findByPublicId')->willReturnCallback(fn () => $this->song);
        $songs->method('getLibraryIdByPublicId')->willReturnCallback(function (PublicId $trackId): ?Uuid {
            self::assertEquals($this->trackId, $trackId);

            return $this->ownerId;
        });
        $libraries = $this->createStub(LibraryRepositoryInterface::class);
        $libraries->method('findByUuid')->willReturnCallback(function (Uuid $libraryId): ?Library {
            self::assertEquals($this->ownerId, $libraryId);

            return $this->library;
        });
        $this->service = new StreamService($songs, new LibraryMediaFileResolver($libraries));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    public function testResolvesTheAbsoluteSourceProducedByDirectoryDiscovery(): void
    {
        $files = (new DirectoryScanner())->scan(new LibraryPath($this->root));
        self::assertCount(1, $files);
        self::assertSame($this->source, $files[0]->getAbsolutePath());
        self::assertSame('album/track.flac', $files[0]->getRelativePath());
        $this->song = $this->songAt($files[0]->getAbsolutePath());
        $this->trackId = $this->song->getPublicId();

        self::assertSame($this->source, $this->service->resolveTrackPath($this->trackId));
    }

    #[DataProvider('rejectedPaths')]
    public function testRejectsSourcesOutsideTheOwningLibrary(string $suffix): void
    {
        $this->song = $this->songAt($this->directory . $suffix);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->resolveTrackPath($this->trackId);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedPaths(): iterable
    {
        yield 'outside absolute path' => ['/outside.flac'];
        yield 'sibling with shared prefix' => ['/library-other/track.flac'];
        yield 'parent traversal escape' => ['/library/../outside.flac'];
        yield 'null byte' => ["/library/album/track.flac\0"];
        yield 'missing source' => ['/library/album/missing.flac'];
        yield 'missing source parent' => ['/library/missing/track.flac'];
        yield 'directory' => ['/library/album'];
        yield 'library root' => ['/library'];
    }

    public function testRejectsRelativeCatalogPaths(): void
    {
        $this->song = $this->songAt('album/track.flac');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->resolveTrackPath($this->trackId);
    }

    #[DataProvider('escapingLinks')]
    public function testRejectsSymlinkEscapes(string $target, string $pathSuffix): void
    {
        self::assertTrue(symlink($this->directory . $target, $this->root . '/link'));
        $this->song = $this->songAt($this->root . '/link' . $pathSuffix);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->resolveTrackPath($this->trackId);
    }

    /** @return iterable<string, array{string, string}> */
    public static function escapingLinks(): iterable
    {
        yield 'file link' => ['/outside.flac', ''];
        yield 'directory link' => ['/library-other', '/track.flac'];
        yield 'dangling link' => ['/not-created', '/track.flac'];
    }

    public function testRechecksASymlinkAfterItsTargetChanges(): void
    {
        $link = $this->root . '/link.flac';
        self::assertTrue(symlink($this->source, $link));
        $this->song = $this->songAt($link);
        self::assertSame($this->source, $this->service->resolveTrackPath($this->trackId));
        self::assertTrue(unlink($link));
        self::assertTrue(symlink($this->directory . '/outside.flac', $link));

        $this->expectException(\InvalidArgumentException::class);

        $this->service->resolveTrackPath($this->trackId);
    }

    public function testResolvesSourcesWhenTheConfiguredLibraryRootIsASymlink(): void
    {
        $alias = $this->directory . '/library-alias';
        self::assertTrue(symlink($this->root, $alias));
        $this->library = $this->libraryAt($alias);

        self::assertSame($this->source, $this->service->resolveTrackPath($this->trackId));
    }

    public function testRejectsMissingSongs(): void
    {
        $this->song = null;

        $this->expectException(\InvalidArgumentException::class);

        $this->service->resolveTrackPath($this->trackId);
    }

    public function testRejectsSongsWithoutAnOwningLibrary(): void
    {
        $this->ownerId = null;

        $this->expectException(\InvalidArgumentException::class);

        $this->service->resolveTrackPath($this->trackId);
    }

    public function testRejectsMissingOwningLibraries(): void
    {
        $this->library = null;

        $this->expectException(\InvalidArgumentException::class);

        $this->service->resolveTrackPath($this->trackId);
    }

    private function libraryAt(string $path): Library
    {
        return Library::create(
            name: 'Playback source library',
            slug: new LibrarySlug('playback-source'),
            path: new LibraryPath($path),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );
    }

    private function songAt(string $path): Song
    {
        return Song::create(new Uuid(), 'Playback track', $path, 10, 'audio/flac');
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            unlink($path);

            return;
        }

        foreach (new \FilesystemIterator($path) as $entry) {
            $this->removeTree($entry->getPathname());
        }
        rmdir($path);
    }
}
