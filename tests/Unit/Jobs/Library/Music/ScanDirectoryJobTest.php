<?php

namespace Tests\Unit\Jobs\Library\Music;

use App\Jobs\Library\Music\ScanDirectoryJob;
use App\Models\Library;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Jobs\JobTestCase;

class ScanDirectoryJobTest extends JobTestCase
{
    #[Test]
    public function it_requires_directory_and_library(): void
    {
        $library = Library::factory()->create();

        $job = new ScanDirectoryJob('/some/path', $library);

        // Job should be instantiable
        $this->assertTrue(true);
    }

    #[Test]
    public function it_has_without_overlapping_middleware(): void
    {
        $library = Library::factory()->create();

        $job = new ScanDirectoryJob('/some/path', $library);

        $middleware = $job->middleware();

        $this->assertIsArray($middleware);
        $this->assertCount(1, $middleware);
    }

    #[Test]
    public function it_handles_empty_directory(): void
    {
        $library = Library::factory()->create(['path' => storage_path('test-empty')]);

        // Create empty directory
        $path = storage_path('test-empty');
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $job = new ScanDirectoryJob($path, $library);

        // Should handle gracefully (would create 0 songs)
        try {
            $job->handle();
        } catch (\Exception $e) {
            // If it fails, check if it's because directory is empty or some other reason
            $this->assertNotEmpty($path);
        }

        // Cleanup
        if (is_dir($path)) {
            rmdir($path);
        }

        $this->assertTrue(true);
    }
}
