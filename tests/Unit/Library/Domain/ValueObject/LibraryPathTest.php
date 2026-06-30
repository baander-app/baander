<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Domain\ValueObject;

use App\Library\Domain\ValueObject\LibraryPath;
use PHPUnit\Framework\TestCase;

final class LibraryPathTest extends TestCase
{
    public function testValidAbsolutePath(): void
    {
        $path = new LibraryPath('/media/music');

        $this->assertSame('/media/music', $path->toString());
    }

    public function testToStringMagicMethod(): void
    {
        $path = new LibraryPath('/var/data');

        $this->assertSame('/var/data', (string) $path);
    }

    public function testTrailingSlashIsStripped(): void
    {
        $path = new LibraryPath('/media/music/');

        $this->assertSame('/media/music', $path->toString());
    }

    public function testTrailingBackslashIsStripped(): void
    {
        $path = new LibraryPath('/media/music\\');

        $this->assertSame('/media/music', $path->toString());
    }

    public function testRootPathThrowsOnEmptyAfterNormalization(): void
    {
        // '/' after rtrim becomes '' which triggers the empty path error
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Library path cannot be empty.');

        new LibraryPath('/');
    }

    public function testDeepPathIsValid(): void
    {
        $path = new LibraryPath('/a/b/c/d/e/f');

        $this->assertSame('/a/b/c/d/e/f', $path->toString());
    }

    public function testPathWithSpacesIsAllowed(): void
    {
        $path = new LibraryPath('/media/my music collection');

        $this->assertSame('/media/my music collection', $path->toString());
    }

    public function testPathWithDotsNotTraversal(): void
    {
        $path = new LibraryPath('/media/.hidden');

        $this->assertSame('/media/.hidden', $path->toString());
    }

    public function testPathWithSingleDotComponent(): void
    {
        $path = new LibraryPath('/media/./music');

        $this->assertSame('/media/./music', $path->toString());
    }

    public function testThrowsOnEmptyPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Library path cannot be empty.');

        new LibraryPath('');
    }

    public function testThrowsOnWhitespaceOnlyPath(): void
    {
        // '   ' after trim by rtrim still has no absolute prefix
        $this->expectException(\InvalidArgumentException::class);

        new LibraryPath('   ');
    }

    public function testThrowsOnRelativePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Library path must be absolute.');

        new LibraryPath('media/music');
    }

    public function testThrowsOnDotRelativePath(): void
    {
        // '.' after rtrim matches the empty/dot check
        $this->expectException(\InvalidArgumentException::class);

        new LibraryPath('.');
    }

    public function testThrowsOnDotSlashPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Library path must be absolute.');

        new LibraryPath('./music');
    }

    public function testThrowsOnPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Library path cannot contain directory traversal sequences.');

        new LibraryPath('/media/../etc/passwd');
    }

    public function testThrowsOnSimpleDoubleDot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Library path cannot contain directory traversal sequences.');

        new LibraryPath('/music/..');
    }

    public function testThrowsOnPathTraversalMidPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Library path cannot contain directory traversal sequences.');

        new LibraryPath('/a/../b/c');
    }

    public function testIsWithinReturnsFalseForNonExistentPath(): void
    {
        $path = new LibraryPath('/nonexistent/path/that/does/not/exist');

        $this->assertFalse($path->isWithin('/media'));
    }

    public function testIsWithinReturnsTrueForPathInsideBase(): void
    {
        $tmpDir = sys_get_temp_dir();
        $subDir = $tmpDir . '/library_test_' . uniqid();
        mkdir($subDir, 0777, true);

        try {
            $path = new LibraryPath($subDir);

            $this->assertTrue($path->isWithin($tmpDir));
        } finally {
            rmdir($subDir);
        }
    }

    public function testIsWithinReturnsFalseForPathOutsideBase(): void
    {
        $tmpDir = sys_get_temp_dir();
        $subDir = $tmpDir . '/library_test_' . uniqid();
        mkdir($subDir, 0777, true);

        try {
            $path = new LibraryPath($subDir);

            $this->assertFalse($path->isWithin('/etc'));
        } finally {
            rmdir($subDir);
        }
    }

    public function testIsWithinReturnsTrueForSameDirectory(): void
    {
        $tmpDir = sys_get_temp_dir();

        $path = new LibraryPath($tmpDir);

        $this->assertTrue($path->isWithin($tmpDir));
    }
}
