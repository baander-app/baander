<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Infrastructure\Filesystem;

use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Filesystem\LibraryMediaFileResolver;
use App\Shared\Domain\ValueObject\FilesystemType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A path resolves in any library only when its real path is an existing file under one library's root. */
final class LibraryMediaFileResolverTest extends TestCase
{
    private string $directory;
    private LibraryMediaFileResolver $resolver;

    protected function setUp(): void
    {
        $this->directory = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/baander-library-files-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory . '/music/album', 0700, true));
        self::assertTrue(mkdir($this->directory . '/films', 0700));
        foreach (['/music/album/track.flac', '/films/film.mkv', '/outside.flac'] as $file) {
            self::assertNotFalse(file_put_contents($this->directory . $file, 'media'));
        }
        self::assertTrue(symlink($this->directory . '/outside.flac', $this->directory . '/music/out.flac'));
        self::assertTrue(symlink($this->directory . '/films/film.mkv', $this->directory . '/music/film.mkv'));
        self::assertTrue(symlink($this->directory . '/gone.flac', $this->directory . '/music/dangling.flac'));

        $libraries = self::createStub(LibraryRepositoryInterface::class);
        $libraries->method('findAllOrdered')->willReturn([
            $this->libraryAt('/music', 'music'),
            $this->libraryAt('/films', 'films'),
        ]);
        $this->resolver = new LibraryMediaFileResolver($libraries);
    }

    protected function tearDown(): void
    {
        foreach (['/music/out.flac', '/music/film.mkv', '/music/dangling.flac', '/music/album/track.flac', '/films/film.mkv', '/outside.flac'] as $file) {
            unlink($this->directory . $file);
        }
        foreach (['/music/album', '/music', '/films', ''] as $directory) {
            rmdir($this->directory . $directory);
        }
    }

    public function testAFileInAnyLibraryResolvesToItsRealPath(): void
    {
        self::assertSame($this->directory . '/music/album/track.flac', $this->resolver->resolveInAnyLibrary($this->directory . '/music/album/../album/track.flac'));
        self::assertSame($this->directory . '/films/film.mkv', $this->resolver->resolveInAnyLibrary($this->directory . '/films/film.mkv'));
    }

    public function testALinkResolvesToItsTargetWhenAnotherLibraryHoldsIt(): void
    {
        self::assertSame($this->directory . '/films/film.mkv', $this->resolver->resolveInAnyLibrary($this->directory . '/music/film.mkv'));
    }

    #[DataProvider('refusedPaths')]
    public function testAPathNoLibraryHoldsAFileAtIsRefused(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver->resolveInAnyLibrary(str_replace('{dir}', $this->directory, $path));
    }

    /** @return iterable<string, array{string}> */
    public static function refusedPaths(): iterable
    {
        yield 'file outside every library' => ['{dir}/outside.flac'];
        yield 'link inside a library to a file outside' => ['{dir}/music/out.flac'];
        yield 'dangling link' => ['{dir}/music/dangling.flac'];
        yield 'missing file' => ['{dir}/music/album/missing.flac'];
        yield 'directory' => ['{dir}/music/album'];
        yield 'parent segments that leave the root' => ['{dir}/music/../outside.flac'];
    }

    #[DataProvider('pathsThatAreNotAbsolute')]
    public function testAPathThatIsNotAbsoluteIsRefusedBeforeAnyLibraryIsRead(string $path): void
    {
        $libraries = $this->createMock(LibraryRepositoryInterface::class);
        $libraries->expects($this->never())->method('findAllOrdered');

        $this->expectException(\InvalidArgumentException::class);

        new LibraryMediaFileResolver($libraries)->resolveInAnyLibrary($path);
    }

    /** @return iterable<string, array{string}> */
    public static function pathsThatAreNotAbsolute(): iterable
    {
        yield 'ftp URL' => ['ftp://127.0.0.1:21/a.mp3'];
        yield 'phar URL' => ['phar:///music/a.phar/a.mp3'];
        yield 'file URL' => ['file:///music/a.mp3'];
        yield 'relative path' => ['music/a.mp3'];
    }

    private function libraryAt(string $root, string $slug): Library
    {
        return Library::create(
            name: 'Library ' . $slug,
            slug: new LibrarySlug($slug),
            path: new LibraryPath($this->directory . $root),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );
    }
}
