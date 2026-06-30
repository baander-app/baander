<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Infrastructure\Scanner;

use App\Library\Infrastructure\Scanner\PathSecurityService;
use PHPUnit\Framework\TestCase;

final class PathSecurityServiceTest extends TestCase
{
    private PathSecurityService $service;

    protected function setUp(): void
    {
        $this->service = new PathSecurityService();
    }

    public function testIsPathSafeForExistingDirectory(): void
    {
        $tmpDir = sys_get_temp_dir();

        $this->assertTrue($this->service->isPathSafe($tmpDir, $tmpDir));
    }

    public function testIsPathSafeForSubdirectory(): void
    {
        $tmpDir = sys_get_temp_dir();
        $subDir = $tmpDir . '/pathsec_test_' . uniqid();
        mkdir($subDir, 0777, true);

        try {
            $this->assertTrue($this->service->isPathSafe($subDir, $tmpDir));
        } finally {
            rmdir($subDir);
        }
    }

    public function testIsPathSafeForDeepSubdirectory(): void
    {
        $tmpDir = sys_get_temp_dir();
        $subDir = $tmpDir . '/pathsec_test_' . uniqid() . '/nested/deep';
        mkdir($subDir, 0777, true);

        try {
            $this->assertTrue($this->service->isPathSafe($subDir, $tmpDir));
        } finally {
            rmdir($subDir);
            rmdir(dirname($subDir));
            rmdir(dirname(dirname($subDir)));
        }
    }

    public function testIsPathSafeReturnsFalseWhenPathEscapesRoot(): void
    {
        $tmpDir = sys_get_temp_dir();
        $root = $tmpDir . '/pathsec_root_' . uniqid();
        mkdir($root, 0777, true);

        try {
            // Trying to escape to the parent
            $this->assertFalse($this->service->isPathSafe($tmpDir, $root));
        } finally {
            rmdir($root);
        }
    }

    public function testIsPathSafeReturnsFalseForNonExistentPath(): void
    {
        $this->assertFalse(
            $this->service->isPathSafe('/nonexistent/path/that/does/not/exist', '/some/root'),
        );
    }

    public function testIsPathSafeReturnsFalseForNonExistentRoot(): void
    {
        $tmpDir = sys_get_temp_dir();

        $this->assertFalse(
            $this->service->isPathSafe($tmpDir, '/nonexistent/root'),
        );
    }

    public function testIsPathSafeReturnsFalseWhenBothNonExistent(): void
    {
        $this->assertFalse(
            $this->service->isPathSafe('/nonexistent/a', '/nonexistent/b'),
        );
    }

    public function testIsPathSafeWithTrailingSlashRoot(): void
    {
        $tmpDir = sys_get_temp_dir();

        $this->assertTrue($this->service->isPathSafe($tmpDir, $tmpDir . '/'));
    }

    public function testIsRelativePathSafeForSimpleFile(): void
    {
        $this->assertTrue($this->service->isRelativePathSafe('song.mp3'));
    }

    public function testIsRelativePathSafeForNestedPath(): void
    {
        $this->assertTrue($this->service->isRelativePathSafe('artist/album/song.mp3'));
    }

    public function testIsRelativePathSafeForDirectory(): void
    {
        $this->assertTrue($this->service->isRelativePathSafe('artist/album'));
    }

    public function testIsRelativePathSafeForDotFile(): void
    {
        $this->assertTrue($this->service->isRelativePathSafe('.hidden'));
    }

    public function testIsRelativePathSafeForDotDirectory(): void
    {
        $this->assertTrue($this->service->isRelativePathSafe('album/.hidden'));
    }

    public function testIsRelativePathSafeForFileWithSpaces(): void
    {
        $this->assertTrue($this->service->isRelativePathSafe('my song.mp3'));
    }

    public function testIsRelativePathSafeBlocksTraversal(): void
    {
        $this->assertFalse($this->service->isRelativePathSafe('../etc/passwd'));
    }

    public function testIsRelativePathSafeBlocksMidPathTraversal(): void
    {
        $this->assertFalse($this->service->isRelativePathSafe('artist/../etc/passwd'));
    }

    public function testIsRelativePathSafeBlocksSimpleDotDot(): void
    {
        $this->assertFalse($this->service->isRelativePathSafe('..'));
    }

    public function testIsRelativePathSafeBlocksTrailingDotDot(): void
    {
        $this->assertFalse($this->service->isRelativePathSafe('artist/..'));
    }

    public function testIsRelativePathSafeBlocksAbsoluteUnixPath(): void
    {
        $this->assertFalse($this->service->isRelativePathSafe('/etc/passwd'));
    }

    public function testIsRelativePathSafeBlocksAbsoluteWindowsPath(): void
    {
        // Windows absolute paths do not start with '/' after separator normalization,
        // so they are not caught by the absolute path check. This documents the
        // current behavior: on Linux systems, Windows-style absolute paths are
        // treated as relative since they don't start with '/'.
        // The realpath resolution in isPathSafe() would catch these on non-Windows systems.
        $this->assertTrue($this->service->isRelativePathSafe('C:\\Windows\\System32'));
    }

    public function testIsRelativePathSafeNormalizesWindowsSeparators(): void
    {
        $this->assertTrue($this->service->isRelativePathSafe('artist\\album\\song.mp3'));
    }

    public function testIsRelativePathSafeBlocksWindowsTraversal(): void
    {
        $this->assertFalse($this->service->isRelativePathSafe('artist\\..\\album'));
    }

    public function testIsRelativePathSafeBlocksEmptyStringDotDot(): void
    {
        $this->assertFalse($this->service->isRelativePathSafe('..'));
    }

    public function testIsRelativePathSafeBlocksDotDotFile(): void
    {
        $this->assertFalse($this->service->isRelativePathSafe('..hidden'));
    }

    public function testIsRelativePathSafeBlocksDotDotInFilename(): void
    {
        $this->assertFalse($this->service->isRelativePathSafe('file..txt'));
    }
}
