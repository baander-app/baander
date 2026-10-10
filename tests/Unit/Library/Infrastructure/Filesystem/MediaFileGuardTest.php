<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Infrastructure\Filesystem;

use App\Library\Application\Port\LibraryMediaFileCheck;
use App\Library\Application\Port\LibraryMediaFileInspection;
use App\Library\Application\Port\LibraryMediaFileLeft;
use App\Library\Application\Port\LibraryMediaFileLeftReason;
use App\Library\Application\Port\LibraryMediaFileVerdict;
use App\Library\Infrastructure\Filesystem\MediaFileGuard;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * The path rules of media file deletion, on a real directory tree: a file is deleted only when
 * both the path and the file a symlink there points to lie under the library root's real path.
 */
final class MediaFileGuardTest extends TestCase
{
    private string $base;
    private string $root;
    private string $outside;
    private MediaFileGuard $guard;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/baander-media-file-guard-' . bin2hex(random_bytes(6));
        $this->root = $this->base . '/library';
        $this->outside = $this->base . '/elsewhere';
        self::assertTrue(mkdir($this->root . '/Artist/Album', 0777, true));
        self::assertTrue(mkdir($this->outside, 0777, true));
        $this->guard = new MediaFileGuard();
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
    }

    public function testAFileInsideTheRootIsRemoved(): void
    {
        $song = $this->file($this->root . '/Artist/Album/01.flac');

        $inspection = $this->inspect($this->root, [$song]);
        self::assertSame([LibraryMediaFileVerdict::Deletable], $this->verdicts($inspection));
        self::assertSame(realpath($this->root), $inspection->root);
        self::assertTrue($inspection->allowsDeletion());

        $result = $this->guard->delete($inspection, static fn (): bool => true);

        self::assertSame([$song], $result->removed);
        self::assertSame([], $result->missing);
        self::assertSame([], $result->left);
        self::assertFileDoesNotExist($song);
    }

    /** Unmounted storage reads as every file missing; deleting their index rows would bring the songs back. */
    public function testALibraryRootThatIsGoneRefusesTheDeletion(): void
    {
        $song = $this->file($this->root . '/Artist/Album/01.flac');
        self::assertTrue(rename($this->root, $this->base . '/unmounted'));

        $inspection = $this->inspect($this->root, [$song]);

        self::assertFalse($inspection->rootAvailable);
        self::assertSame([LibraryMediaFileVerdict::Missing], $this->verdicts($inspection));
        self::assertFalse($inspection->allowsDeletion());
    }

    public function testAPathOutsideTheRootIsRefused(): void
    {
        $inside = $this->file($this->root . '/Artist/Album/01.flac');
        $outside = $this->file($this->outside . '/02.flac');

        $inspection = $this->inspect($this->root, [$inside, $outside]);

        self::assertSame([LibraryMediaFileVerdict::Deletable, LibraryMediaFileVerdict::OutsideRoot], $this->verdicts($inspection));
        self::assertFalse($inspection->allowsDeletion());
    }

    public function testARootThatIsASymlinkAcceptsFilesUnderItsRealPath(): void
    {
        $link = $this->base . '/library-link';
        self::assertTrue(symlink($this->root, $link));
        $throughLink = $this->file($link . '/Artist/Album/01.flac');
        $direct = $this->file($this->root . '/Artist/Album/02.flac');

        $inspection = $this->inspect($link, [$throughLink, $direct]);

        self::assertSame(realpath($this->root), $inspection->root);
        self::assertSame([LibraryMediaFileVerdict::Deletable, LibraryMediaFileVerdict::Deletable], $this->verdicts($inspection));
        self::assertSame([$throughLink, $direct], $this->guard->delete($inspection, static fn (): bool => true)->removed);
        self::assertFileDoesNotExist($this->root . '/Artist/Album/01.flac');
        self::assertFileDoesNotExist($direct);
    }

    public function testASymlinkToAFileOutsideTheRootIsRefused(): void
    {
        $target = $this->file($this->outside . '/01.flac');
        $link = $this->root . '/Artist/Album/01.flac';
        self::assertTrue(symlink($target, $link));

        self::assertSame([LibraryMediaFileVerdict::OutsideRoot], $this->verdicts($this->inspect($this->root, [$link])));
    }

    public function testASymlinkToAFileInsideTheRootIsRemovedAsALink(): void
    {
        $target = $this->file($this->root . '/Artist/Album/01.flac');
        $link = $this->root . '/Artist/01.flac';
        self::assertTrue(symlink($target, $link));

        $inspection = $this->inspect($this->root, [$link]);
        self::assertSame([LibraryMediaFileVerdict::Deletable], $this->verdicts($inspection));

        self::assertSame([$link], $this->guard->delete($inspection, static fn (): bool => true)->removed);
        self::assertFalse(is_link($link));
        self::assertFileExists($target);
    }

    public function testASymlinkOutsideTheRootIsRefusedEvenWhenItPointsInside(): void
    {
        $target = $this->file($this->root . '/Artist/Album/01.flac');
        $link = $this->outside . '/01.flac';
        self::assertTrue(symlink($target, $link));

        self::assertSame([LibraryMediaFileVerdict::OutsideRoot], $this->verdicts($this->inspect($this->root, [$link])));
    }

    public function testAFileUnderAParentDirectorySymlinkedOutsideTheRootIsRefused(): void
    {
        $this->file($this->outside . '/Album/01.flac');
        self::assertTrue(symlink($this->outside . '/Album', $this->root . '/Artist/Linked'));

        $inspection = $this->inspect($this->root, [$this->root . '/Artist/Linked/01.flac']);

        self::assertSame([LibraryMediaFileVerdict::OutsideRoot], $this->verdicts($inspection));
    }

    public function testAPathThatEscapesTheRootWithDotDotIsRefused(): void
    {
        $this->file($this->outside . '/01.flac');

        $inspection = $this->inspect($this->root, [
            $this->root . '/Artist/../../elsewhere/01.flac',
            $this->root . '/Artist/../../elsewhere/missing.flac',
            $this->root . '/Artist/Missing/../../../elsewhere/missing.flac',
        ]);

        self::assertSame(array_fill(0, 3, LibraryMediaFileVerdict::OutsideRoot), $this->verdicts($inspection));
    }

    public function testADotDotThatStaysInsideTheRootIsAccepted(): void
    {
        $song = $this->file($this->root . '/Artist/Album/01.flac');

        $inspection = $this->inspect($this->root, [$this->root . '/Artist/../Artist/Album/01.flac']);

        self::assertSame([LibraryMediaFileVerdict::Deletable], $this->verdicts($inspection));
        $this->guard->delete($inspection, static fn (): bool => true);
        self::assertFileDoesNotExist($song);
    }

    public function testARelativePathIsRefused(): void
    {
        $inspection = $this->inspect($this->root, ['Artist/Album/01.flac']);

        self::assertSame([LibraryMediaFileVerdict::OutsideRoot], $this->verdicts($inspection));
    }

    public function testADirectoryTheServerCannotWriteIsNamed(): void
    {
        $song = $this->file($this->root . '/Artist/Album/01.flac');
        $this->makeReadOnly($this->root . '/Artist/Album');

        $inspection = $this->inspect($this->root, [$song]);

        self::assertEquals(
            [new LibraryMediaFileCheck($song, LibraryMediaFileVerdict::DirectoryNotWritable, realpath($this->root . '/Artist/Album'))],
            $inspection->files,
        );
        self::assertFalse($inspection->allowsDeletion());
    }

    public function testAFileThatIsAlreadyMissingIsReportedMissing(): void
    {
        $gone = $this->root . '/Artist/Album/gone.flac';
        $goneDirectory = $this->root . '/Gone/Album/gone.flac';
        $dangling = $this->root . '/Artist/Album/dangling.flac';
        self::assertTrue(symlink($this->outside . '/deleted.flac', $dangling));

        $inspection = $this->inspect($this->root, [$gone, $goneDirectory, $dangling]);

        self::assertSame(array_fill(0, 3, LibraryMediaFileVerdict::Missing), $this->verdicts($inspection));
        self::assertTrue($inspection->allowsDeletion());
        $result = $this->guard->delete($inspection, static fn (): bool => true);
        self::assertSame([$gone, $goneDirectory, $dangling], $result->missing);
        self::assertSame([], $result->removed);
        self::assertTrue(is_link($dangling), 'A missing file is not unlinked.');
    }

    public function testAFileThatResolvesOutsideTheRootAfterTheCheckIsLeft(): void
    {
        $song = $this->file($this->root . '/Artist/Album/01.flac');
        $target = $this->file($this->outside . '/01.flac');
        $inspection = $this->inspect($this->root, [$song]);
        self::assertSame([LibraryMediaFileVerdict::Deletable], $this->verdicts($inspection));

        self::assertTrue(unlink($song));
        self::assertTrue(symlink($target, $song));
        $result = $this->guard->delete($inspection, static fn (): bool => true);

        self::assertSame([], $result->removed);
        self::assertCount(1, $result->left);
        self::assertSame($song, $result->left[0]->path);
        self::assertSame(LibraryMediaFileLeftReason::OutsideRoot, $result->left[0]->reason);
        self::assertTrue(is_link($song));
        self::assertFileExists($target);
    }

    public function testAFileThatCannotBeUnlinkedAfterTheCheckIsLeftWithTheReason(): void
    {
        $song = $this->file($this->root . '/Artist/Album/01.flac');
        $inspection = $this->inspect($this->root, [$song]);
        self::assertSame([LibraryMediaFileVerdict::Deletable], $this->verdicts($inspection));

        $this->makeReadOnly($this->root . '/Artist/Album');
        $result = $this->guard->delete($inspection, static fn (): bool => true);

        self::assertSame([], $result->removed);
        self::assertTrue($result->leftAny());
        self::assertSame($song, $result->left[0]->path);
        self::assertSame(LibraryMediaFileLeftReason::UnlinkFailed, $result->left[0]->reason);
        self::assertStringContainsString('Permission denied', $result->left[0]->detail);
        self::assertFileExists($song);
    }

    public function testADeleteThatLosesItsClaimStopsUnlinkingAndLeavesTheRest(): void
    {
        $first = $this->file($this->root . '/Artist/Album/01.flac');
        $second = $this->file($this->root . '/Artist/Album/02.flac');
        $third = $this->file($this->root . '/Artist/Album/03.flac');
        $gone = $this->root . '/Artist/Album/04.flac';
        $inspection = $this->inspect($this->root, [$first, $gone, $second, $third]);
        $answers = [true, false];
        $asked = 0;

        $result = $this->guard->delete($inspection, static function () use (&$answers, &$asked): bool {
            ++$asked;

            return array_shift($answers) ?? self::fail('Once the claim is lost, the guard stops asking.');
        });

        self::assertSame(2, $asked, 'Asked before each unlink, not for a file already missing.');
        self::assertSame([$first], $result->removed);
        self::assertSame([$gone], $result->missing);
        self::assertSame([$second, $third], array_map(static fn (LibraryMediaFileLeft $left): string => $left->path, $result->left));
        self::assertSame([LibraryMediaFileLeftReason::ClaimLost, LibraryMediaFileLeftReason::ClaimLost], array_map(static fn (LibraryMediaFileLeft $left): LibraryMediaFileLeftReason => $left->reason, $result->left));
        self::assertFileDoesNotExist($first);
        self::assertFileExists($second);
        self::assertFileExists($third);
    }

    public function testARefusedInspectionDeletesNothing(): void
    {
        $inside = $this->file($this->root . '/Artist/Album/01.flac');
        $inspection = $this->inspect($this->root, [$inside, $this->file($this->outside . '/02.flac')]);

        try {
            $this->guard->delete($inspection, static fn (): bool => true);
            self::fail('A refused inspection must not delete files.');
        } catch (\LogicException) {
        }

        self::assertFileExists($inside);
    }

    /** @param list<string> $paths */
    private function inspect(string $libraryPath, array $paths): LibraryMediaFileInspection
    {
        return $this->guard->inspect(new Uuid(), $libraryPath, $paths, libraryBusy: false);
    }

    /** @return list<LibraryMediaFileVerdict> */
    private function verdicts(LibraryMediaFileInspection $inspection): array
    {
        return array_map(static fn (LibraryMediaFileCheck $file): LibraryMediaFileVerdict => $file->verdict, $inspection->files);
    }

    private function file(string $path): string
    {
        if (!is_dir(dirname($path))) {
            self::assertTrue(mkdir(dirname($path), 0777, true));
        }
        self::assertNotFalse(file_put_contents($path, 'audio'));

        return $path;
    }

    /** Root ignores directory modes, so the read-only cases need an unprivileged test user. */
    private function makeReadOnly(string $directory): void
    {
        self::assertTrue(chmod($directory, 0555));
        clearstatcache(true);
        self::assertFalse(is_writable($directory), 'The read-only cases need a test user other than root.');
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        chmod($path, 0777);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
