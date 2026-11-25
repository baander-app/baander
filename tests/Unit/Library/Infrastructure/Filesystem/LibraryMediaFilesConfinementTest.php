<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Infrastructure\Filesystem;

use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Filesystem\LibraryMediaFiles;
use App\Library\Infrastructure\Filesystem\MediaFileGuard;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

/** An import reads a sidecar, and counts a file as present, only inside the library root. */
final class LibraryMediaFilesConfinementTest extends TestCase
{
    private const int LIMIT = 1_048_576;

    private string $directory;
    private string $root;
    private Uuid $libraryId;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-media-confinement-' . bin2hex(random_bytes(6));
        $this->root = $this->directory . '/library';
        mkdir($this->root . '/album', 0700, true);
        mkdir($this->directory . '/elsewhere', 0700, true);
        file_put_contents($this->root . '/album/01 Track.flac', 'audio');
        file_put_contents($this->directory . '/elsewhere/secret.txt', "[00:01.00]Not lyrics\n");
        $this->libraryId = new Uuid();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testASidecarInsideTheLibraryIsRead(): void
    {
        file_put_contents($this->root . '/album/01 Track.lrc', "[00:01.00]Sidecar line\n");

        self::assertSame("[00:01.00]Sidecar line\n", $this->files()->readSidecar($this->libraryId, $this->root . '/album/01 Track.lrc', self::LIMIT));
    }

    public function testASidecarLinkThatLeavesTheLibraryIsNotRead(): void
    {
        self::assertTrue(symlink($this->directory . '/elsewhere/secret.txt', $this->root . '/album/01 Track.lrc'));

        self::assertNull($this->files()->readSidecar($this->libraryId, $this->root . '/album/01 Track.lrc', self::LIMIT));
    }

    public function testASidecarOverTheLimitIsNotRead(): void
    {
        file_put_contents($this->root . '/album/01 Track.lrc', str_repeat('x', self::LIMIT + 1));

        self::assertNull($this->files()->readSidecar($this->libraryId, $this->root . '/album/01 Track.lrc', self::LIMIT));
        file_put_contents($this->root . '/album/01 Track.lrc', str_repeat('x', self::LIMIT));
        self::assertSame(self::LIMIT, strlen((string) $this->files()->readSidecar($this->libraryId, $this->root . '/album/01 Track.lrc', self::LIMIT)));
    }

    public function testAMissingSidecarOrAnUnknownLibraryReadsNothing(): void
    {
        self::assertNull($this->files()->readSidecar($this->libraryId, $this->root . '/album/01 Track.lrc', self::LIMIT));
        self::assertNull($this->files(known: false)->readSidecar($this->libraryId, $this->root . '/album/01 Track.flac', self::LIMIT));
    }

    public function testAFileRetargetedOutsideTheLibraryCountsAsMissingAndIsMarkedUnavailable(): void
    {
        $link = $this->root . '/album/02 Linked.flac';
        self::assertTrue(symlink($this->directory . '/elsewhere/secret.txt', $link));
        $present = $this->root . '/album/01 Track.flac';
        $index = $this->createMock(LibraryFileIndexRepositoryInterface::class);
        $index->expects($this->once())->method('markUnavailableByPaths')->with($this->libraryId, [$link]);

        self::assertSame([$link], $this->files(index: $index)->markMissingUnavailable($this->libraryId, [$present, $link]));
    }

    private function files(bool $known = true, ?LibraryFileIndexRepositoryInterface $index = null): LibraryMediaFiles
    {
        $libraries = $this->createStub(LibraryRepositoryInterface::class);
        $libraries->method('findByUuid')->willReturn($known ? Library::create(
            name: 'Confined library',
            slug: new LibrarySlug('confined-library'),
            path: new LibraryPath($this->root),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        ) : null);

        return new LibraryMediaFiles(
            $libraries,
            $index ?? $this->createStub(LibraryFileIndexRepositoryInterface::class),
            new MediaFileGuard(),
            $this->createStub(EntityManagerInterface::class),
            new MockClock(),
            new NullLogger(),
        );
    }
}
