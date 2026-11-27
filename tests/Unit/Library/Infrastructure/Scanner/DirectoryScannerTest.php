<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Infrastructure\Scanner;

use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Infrastructure\Scanner\DirectoryScanner;
use App\Library\Application\DTO\MediaFile;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Filesystem\Filesystem;

/** Discovery follows a file link only while its target stays inside the library. */
final class DirectoryScannerTest extends TestCase
{
    private string $directory;
    private string $root;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-directory-scanner-' . (random_bytes(6) |> bin2hex(...));
        $this->root = $this->directory . '/library';
        mkdir($this->root . '/album', 0700, true);
        mkdir($this->directory . '/elsewhere', 0700, true);
        file_put_contents($this->root . '/album/track.flac', 'inside');
        file_put_contents($this->directory . '/elsewhere/private.mkv', 'outside');
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testALinkWhoseTargetLeavesTheLibraryIsSkippedAndReported(): void
    {
        $link = $this->root . '/album/Holiday Clip (2021).mkv';
        self::assertTrue(symlink($this->directory . '/elsewhere/private.mkv', $link));

        self::assertSame([$this->root . '/album/track.flac'], $this->scan($this->root));
        self::assertSame([['warning', $link]], $this->logger->records);
    }

    public function testALinkWhoseTargetStaysInsideTheLibraryIsDiscoveredUnderItsOwnName(): void
    {
        self::assertTrue(symlink('track.flac', $this->root . '/album/alias.flac'));

        self::assertSame([$this->root . '/album/alias.flac', $this->root . '/album/track.flac'], $this->scan($this->root));
        self::assertSame([], $this->logger->records);
    }

    public function testALinkRetargetedOutsideIsSkippedByTheNextScan(): void
    {
        $link = $this->root . '/album/alias.flac';
        self::assertTrue(symlink($this->root . '/album/track.flac', $link));
        self::assertContains($link, $this->scan($this->root));
        self::assertTrue(unlink($link));
        self::assertTrue(symlink($this->directory . '/elsewhere/private.mkv', $link));

        self::assertNotContains($link, $this->scan($this->root));
    }

    public function testALibraryWhoseRootIsALinkStillDiscoversItsFiles(): void
    {
        $alias = $this->directory . '/library-alias';
        self::assertTrue(symlink($this->root, $alias));

        self::assertSame([realpath($this->root) . '/album/track.flac'], $this->scan($alias));
    }

    public function testASiblingDirectorySharingTheRootsPrefixIsOutside(): void
    {
        mkdir($this->directory . '/library-other', 0700);
        file_put_contents($this->directory . '/library-other/track.flac', 'sibling');
        self::assertTrue(symlink($this->directory . '/library-other/track.flac', $this->root . '/album/sibling.flac'));

        self::assertSame([$this->root . '/album/track.flac'], $this->scan($this->root));
    }

    /** @return list<string> the absolute paths discovered, sorted */
    private function scan(string $root): array
    {
        $paths = array_map(
            static fn(MediaFile $file): string => $file->getAbsolutePath(),
            new DirectoryScanner($this->logger)->scan(new LibraryPath($root)),
        );
        sort($paths);

        return $paths;
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string}> the level and path of each record */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) ($context['path'] ?? '')];
    }
}
