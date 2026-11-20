<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\LogViewer;

use App\Shared\Infrastructure\LogViewer\ArchiveReader;
use App\Shared\Infrastructure\LogViewer\FileRecordReader;
use PHPUnit\Framework\TestCase;

final class ArchiveReaderTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];
    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }
    private function path(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/baander-log-' . bin2hex(random_bytes(8)) . $suffix;
        $this->paths[] = $path;
        return $path;
    }
    public function testGzipAndZipStreamMultilineMembers(): void
    {
        $body = "[2026-11-20T10:00:00Z] app.ERROR: failed\n  frame\n[2026-11-20T10:00:01Z] app.INFO: done\n";
        $gzip = $this->path('.gz');
        file_put_contents($gzip, gzencode($body));
        $reader = new ArchiveReader();
        $records = iterator_to_array($reader->records($gzip));
        self::assertCount(2, $records);
        self::assertStringContainsString('frame', $records[0]);
        $zipPath = $this->path('.zip');
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('logs/app.log', $body);
        $zip->close();
        self::assertSame(['logs/app.log'], $reader->members($zipPath));
        self::assertSame($records, iterator_to_array($reader->records($zipPath, 'logs/app.log')));
    }
    public function testUnsafeMemberCannotBeRead(): void
    {
        $path = $this->path('.zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('../escape.log', 'private');
        $zip->close();
        $this->expectException(\RuntimeException::class);
        (new ArchiveReader())->members($path);
    }
    public function testSymlinkMemberCannotBeRead(): void
    {
        $path = $this->path('.zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('link', '/etc/passwd');
        $zip->setExternalAttributesName('link', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $this->expectException(\RuntimeException::class);
        (new ArchiveReader())->members($path);
    }
    public function testCorruptGzipIsExplicit(): void
    {
        $path = $this->path('.gz');
        file_put_contents($path, substr(gzencode('a log'), 0, -5));
        $this->expectException(\RuntimeException::class);
        iterator_to_array((new ArchiveReader())->records($path));
    }
    public function testExpansionIsBounded(): void
    {
        $path = $this->path('.gz');
        file_put_contents($path, gzencode(str_repeat('x', 10000)));
        $this->expectException(\RuntimeException::class);
        iterator_to_array((new ArchiveReader(100))->records($path));
    }
    public function testFileReaderPreservesPartialRecordsAndDetectsCopyTruncate(): void
    {
        $path = $this->path('.log');
        file_put_contents($path, "first\n  frame\nsec");
        $reader = new FileRecordReader();
        $first = $reader->read($path);
        self::assertSame("first\n  frame\n", $first['records'][0]['raw']);
        file_put_contents($path, "ond\nthird\n", FILE_APPEND);
        $second = $reader->read($path, $first['checkpoint']);
        self::assertSame("second\n", $second['records'][0]['raw']);
        file_put_contents($path, "replacement is longer than previous offset\nnext\n");
        $third = $reader->read($path, $second['checkpoint']);
        self::assertNotSame($second['checkpoint']['generation'], $third['checkpoint']['generation']);
        self::assertNotEmpty($third['issues']);
    }
    public function testRotationChangesGenerationWithoutReplayingTheOldIdentity(): void
    {
        $path = $this->path('.log');
        file_put_contents($path, "old record\nnext\n");
        $reader = new FileRecordReader();
        $first = $reader->read($path);
        rename($path, $this->path('.rotated'));
        file_put_contents($path, "new record\nnext\n");
        $rotated = $reader->read($path, $first['checkpoint']);
        self::assertNotSame($first['checkpoint']['generation'], $rotated['checkpoint']['generation']);
        self::assertSame("new record\n", $rotated['records'][0]['raw']);
        self::assertNotEmpty($rotated['issues']);
    }

    public function testNestedArchiveIsRejected(): void
    {
        $path = $this->path('.gz');
        $nested = substr($path, 0, -3) . '.zip.gz';
        $this->paths[] = $nested;
        file_put_contents($nested, gzencode('archive payload'));
        $this->expectException(\RuntimeException::class);
        (new ArchiveReader())->members($nested);
    }

    public function testMemberInventoryIsBounded(): void
    {
        $path = $this->path('.zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('one.log', 'one');
        $zip->addFromString('two.log', 'two');
        $zip->close();
        $this->expectException(\RuntimeException::class);
        (new ArchiveReader(maxMembers: 1))->members($path);
    }
    public function testOversizedArchiveRecordKeepsItsTruncationSignal(): void
    {
        $path = $this->path('.gz');
        file_put_contents($path, gzencode(str_repeat('x', 400000) . "\nnext\n"));
        $records = iterator_to_array((new ArchiveReader())->records($path));
        self::assertCount(2, $records);
        self::assertSame(262145, strlen($records[0]));
        self::assertTrue((new \App\Shared\Infrastructure\LogViewer\LogNormalizer())->normalize($records[0])['truncated']);
    }

    public function testConcatenatedGzipDoesNotSilentlyDropTheSecondStream(): void
    {
        $path = $this->path('.gz');
        file_put_contents($path, gzencode('one') . gzencode('two'));
        $this->expectException(\RuntimeException::class);
        iterator_to_array((new ArchiveReader())->records($path));
    }
}
