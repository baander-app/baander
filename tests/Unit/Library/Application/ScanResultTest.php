<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Application;

use App\Library\Application\ScanResult;
use App\Library\Domain\Model\DiscoveredFile;
use PHPUnit\Framework\TestCase;

final class ScanResultTest extends TestCase
{
    public function testDefaultDirectoriesIsEmpty(): void
    {
        $result = new ScanResult(
            filesDiscovered: 10,
            filesProcessed: 8,
            filesSkipped: 2,
        );

        $this->assertSame([], $result->directories);
    }

    public function testDirectoriesArePreserved(): void
    {
        $file = new DiscoveredFile(
            absolutePath: '/music/album/track.mp3',
            relativePath: 'album/track.mp3',
            extension: 'mp3',
            size: 1024,
            modifiedAt: time(),
            hash: 'abc123',
        );

        $directories = ['/music/album' => [$file]];

        $result = new ScanResult(
            filesDiscovered: 5,
            filesProcessed: 3,
            filesSkipped: 1,
            directories: $directories,
        );

        $this->assertSame($directories, $result->directories);
        $this->assertCount(1, $result->directories['/music/album']);
    }

    public function testAllFieldsAccessible(): void
    {
        $result = new ScanResult(
            filesDiscovered: 42,
            filesProcessed: 30,
            filesSkipped: 10,
            directories: [],
        );

        $this->assertSame(42, $result->filesDiscovered);
        $this->assertSame(30, $result->filesProcessed);
        $this->assertSame(10, $result->filesSkipped);
        $this->assertSame([], $result->directories);
    }
}
