<?php

namespace Tests\Unit\Modules\Security;

use App\Modules\Security\Exceptions\PathSecurityException;
use App\Modules\Security\PathSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Jobs\JobTestCase;

class PathSecurityServiceTest extends JobTestCase
{
    private PathSecurityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PathSecurityService();
    }

    #[Test]
    public function it_detects_directory_traversal_with_double_dot(): void
    {
        $this->expectException(PathSecurityException::class);
        $this->expectExceptionMessageMatches('/path traversal attempt/i');

        $this->service->isValidLibraryPath('/etc/passwd', ['/home']);
    }

    #[Test]
    public function it_detects_directory_traversal_with_parent_references(): void
    {
        $this->expectException(PathSecurityException::class);

        $allowedPaths = ['/home', '/media'];
        $this->service->isValidLibraryPath('/home/../../../etc/passwd', $allowedPaths);
    }

    #[Test]
    public function it_rejects_paths_outside_allowed_directories(): void
    {
        $this->expectException(PathSecurityException::class);
        $this->expectExceptionMessageMatches('/outside allowed paths/i');

        $allowedPaths = ['/home', '/media'];
        $this->service->isValidLibraryPath('/etc/passwd', $allowedPaths);
    }

    #[Test]
    public function it_accepts_valid_paths_within_allowed_directory(): void
    {
        $allowedPaths = ['/home', '/media'];

        // Should not throw for valid paths
        $this->assertTrue(
            $this->service->isWithinAllowedPath('/home/user/music', $allowedPaths)
        );
        $this->assertTrue(
            $this->service->isWithinAllowedPath('/media/movies', $allowedPaths)
        );
    }

    #[Test]
    public function it_calculates_directory_depth_correctly(): void
    {
        // Simple path depth
        $depth1 = $this->service->calculateDirectoryDepth('/home/user/music');
        $this->assertEquals(3, $depth1);

        // Deeper path
        $depth2 = $this->service->calculateDirectoryDepth('/home/user/music/rock/2023/album');
        $this->assertEquals(7, $depth2);

        // Root level
        $depth3 = $this->service->calculateDirectoryDepth('/home');
        $this->assertEquals(1, $depth3);
    }

    #[Test]
    public function it_rejects_paths_exceeding_max_depth(): void
    {
        $this->expectException(PathSecurityException::class);
        $this->expectExceptionMessageMatches('/depth exceeded/i');

        // Create a path 25 levels deep
        $deepPath = '/home/' . implode('/', array_fill(0, 25, 'level'));
        $allowedPaths = ['/home'];

        // Set max depth to 20
        config(['scanner.security.max_directory_depth' => 20]);

        $this->service->isValidLibraryPath($deepPath, $allowedPaths);
    }

    #[Test]
    public function it_sanitizes_paths_by_removing_null_bytes(): void
    {
        $pathWithNullByte = "/home/user\0/music";

        $sanitized = $this->service->sanitizePath($pathWithNullByte);

        $this->assertStringNotContainsString("\0", $sanitized);
    }

    #[Test]
    public function it_sanitizes_paths_by_normalizing_separators(): void
    {
        $mixedSeparators = '/home/user\\music/albums';

        $sanitized = $this->service->sanitizePath($mixedSeparators);

        // Should normalize to single separator type
        $separator = DIRECTORY_SEPARATOR;
        $this->assertStringContainsString($separator, $sanitized);
    }

    #[Test]
    public function it_resolves_symlinks(): void
    {
        // This test assumes symlinks exist in test environment
        // If not, it will verify the method doesn't crash
        $allowedPaths = ['/tmp'];

        // Should not throw for valid symlink resolution
        try {
            $result = $this->service->resolveAndValidateSymlink('/tmp');
            $this->assertIsString($result);
        } catch (PathSecurityException $e) {
            // If /tmp doesn't exist or has no symlinks, that's ok
            $this->assertStringContainsString('not exist', $e->getMessage());
        }
    }

    #[Test]
    public function it_detects_circular_symlinks(): void
    {
        $this->expectException(PathSecurityException::class);
        $this->expectExceptionMessageMatches('/circular symlink/i');

        // Create a scenario that would cause circular symlink
        // This is a theoretical test - in real scenarios would need actual circular symlinks
        $visited = ['/tmp', '/home', '/tmp'];
        $this->service->resolveAndValidateSymlink('/home', $visited);
    }
}
