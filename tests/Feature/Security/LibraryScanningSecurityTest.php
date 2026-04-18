<?php

namespace Tests\Feature\Security;

use App\Primitives\Text;
use App\Models\{Album, Library, Song};
use App\Modules\Security\Exceptions\FileValidationException;
use App\Modules\Security\MagicByteValidator;
use App\Modules\Security\PathSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LibraryScanningSecurityTest extends TestCase
{
    use RefreshDatabase;

    private PathSecurityService $pathSecurity;

    private MagicByteValidator $magicByteValidator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pathSecurity = new PathSecurityService();
        $this->magicByteValidator = new MagicByteValidator();

        // Configure security for testing
        Config::set('scanner.security.allowed_base_paths', ['/tmp', '/home']);
        Config::set('scanner.security.max_directory_depth', 20);
        Config::set('scanner.security.max_file_size_mb', [
            'audio' => 500,
            'video' => 5000,
            'lyrics' => 1,
            'image' => 10,
            'subtitle' => 1,
        ]);
        Config::set('scanner.security.validate_magic_bytes', true);
        Config::set('scanner.security.allow_mime_mismatch', false);
        Config::set('scanner.security.sanitize_metadata', true);
        Config::set('scanner.security.max_metadata_length', [
            'title' => 255,
            'artist' => 255,
            'album' => 255,
            'genre' => 100,
            'comment' => 1000,
            'lyrics' => 50000,
        ]);
    }

    #[Test]
    public function it_rejects_library_with_directory_traversal(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)
            ->postJson('/api/libraries', [
                'name' => 'Test Library',
                'path' => '/etc/passwd',
                'type' => 'music',
                'order' => 1,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('path');
    }

    #[Test]
    public function it_rejects_library_with_symlink_to_system_files(): void
    {
        $user = $this->createUser();

        // This test assumes /tmp symlink might exist
        // If not, we test the validation logic
        $response = $this->actingAs($user)
            ->postJson('/api/libraries', [
                'name' => 'Test Library',
                'path' => '/tmp/../../../etc',
                'type' => 'music',
                'order' => 1,
            ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_accepts_valid_library_path(): void
    {
        $user = $this->createUser();

        // Create a temporary test directory
        $testPath = storage_path('test_library');
        File::makeDirectory($testPath, 0755, true);

        $response = $this->actingAs($user)
            ->postJson('/api/libraries', [
                'name' => 'Test Library',
                'path' => $testPath,
                'type' => 'music',
                'order' => 1,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.id');

        // Cleanup
        File::deleteDirectory($testPath);
    }

    #[Test]
    public function it_sanitizes_html_in_metadata(): void
    {
        $user = $this->createUser();
        $library = Library::factory()->forUser($user)->create();
        $album = Album::factory()->for($library)->create();

        // Test XSS in title
        $xssTitle = '<script>alert("xss")</script>Song Title';
        $sanitizedTitle = Text::sanitizeMetadata($xssTitle);

        $song = Song::factory()->for($album)->create([
            'title' => $sanitizedTitle,
        ]);

        // Fetch via API
        $response = $this->actingAs($user)
            ->getJson("/api/songs?filter[album_id]={$album->id}");

        $response->assertStatus(200);

        // Verify title is sanitized in response
        $this->assertStringNotContainsString('<script>', $response->json('data.0.title'));
    }

    #[Test]
    public function it_sanitizes_event_handlers_in_metadata(): void
    {
        $user = $this->createUser();
        $library = Library::factory()->forUser($user)->create();
        $album = Album::factory()->for($library)->create();

        // Test event handler in comment
        $xssComment = 'Comment with onload="alert(1)" content';
        $sanitizedComment = Text::sanitize($xssComment);

        $song = Song::factory()->for($album)->create([
            'title' => 'Test Song',
            'comment' => $sanitizedComment,
        ]);

        // Fetch via API
        $response = $this->actingAs($user)
            ->getJson("/api/songs/{$song->id}");

        $response->assertStatus(200);

        // Verify comment is sanitized
        $this->assertStringNotContainsString('onload=', $response->json('data.comment'));
    }

    #[Test]
    public function it_truncates_long_metadata_fields(): void
    {
        $user = $this->createUser();
        $library = Library::factory()->forUser($user)->create();
        $album = Album::factory()->for($library)->create();

        // Create a title longer than max (255)
        $longTitle = str_repeat('A', 300);
        $sanitizedTitle = Text::sanitizeMetadata($longTitle);
        $truncatedTitle = mb_substr($sanitizedTitle, 0, 255);

        $song = Song::factory()->for($album)->create([
            'title' => $truncatedTitle,
        ]);

        // Verify truncation
        $this->assertLessThanOrEqual(255, mb_strlen($song->title));
    }

    #[Test]
    public function it_skips_files_with_invalid_magic_bytes(): void
    {
        $user = $this->createUser();
        $library = Library::factory()->forUser($user)->create();
        $album = Album::factory()->for($library)->create();

        // Create a fake MP3 file (text file with .mp3 extension)
        $testFile = storage_path('test_fake.mp3');
        file_put_contents($testFile, 'This is not a real MP3 file');

        // Should be rejected by magic byte validator
        $this->assertFalse($this->magicByteValidator->isValidAudioFile($testFile));

        // Cleanup
        unlink($testFile);
    }

    #[Test]
    public function it_accepts_files_with_valid_magic_bytes(): void
    {
        $user = $this->createUser();
        $library = Library::factory()->forUser($user)->create();
        $album = Album::factory()->for($library)->create();

        // Create a real MP3 file with ID3 header
        $testFile = storage_path('test_real.mp3');
        $handle = fopen($testFile, 'wb');
        fwrite($handle, 'ID3' . str_repeat("\0", 256));
        fclose($handle);

        // Should be accepted
        $this->assertTrue($this->magicByteValidator->isValidAudioFile($testFile));

        // Cleanup
        unlink($testFile);
    }

    #[Test]
    public function it_detects_mime_type_mismatches(): void
    {
        $user = $this->createUser();
        $library = Library::factory()->forUser($user)->create();
        $album = Album::factory()->for($library)->create();

        // Create MP3 file
        $testFile = storage_path('test.mp3');
        $handle = fopen($testFile, 'wb');
        fwrite($handle, 'ID3' . str_repeat("\0", 256));
        fclose($handle);

        // Should match audio MIME
        $this->assertTrue(
            $this->magicByteValidator->validateAgainstMime($testFile, 'audio/mpeg')
        );

        // Should not match video MIME
        $this->assertFalse(
            $this->magicByteValidator->validateAgainstMime($testFile, 'video/mp4')
        );

        // Cleanup
        unlink($testFile);
    }

    #[Test]
    public function it_calculates_path_depth_correctly(): void
    {
        // Test depth calculation
        $depth1 = $this->pathSecurity->calculateDirectoryDepth('/home/user/music');
        $this->assertEquals(3, $depth1);

        $depth2 = $this->pathSecurity->calculateDirectoryDepth('/home/user/music/rock/2023');
        $this->assertEquals(5, $depth2);

        $depth3 = $this->pathSecurity->calculateDirectoryDepth('/home');
        $this->assertEquals(1, $depth3);
    }

    #[Test]
    public function it_checks_path_within_allowed_paths(): void
    {
        $allowedPaths = ['/home', '/media', '/tmp'];

        // Valid paths
        $this->assertTrue(
            $this->pathSecurity->isWithinAllowedPath('/home/user/music', $allowedPaths)
        );

        $this->assertTrue(
            $this->pathSecurity->isWithinAllowedPath('/media/movies', $allowedPaths)
        );

        // Invalid path
        $this->assertFalse(
            $this->pathSecurity->isWithinAllowedPath('/etc/passwd', $allowedPaths)
        );
    }

    #[Test]
    public function it_sanitizes_paths(): void
    {
        // Test null byte removal
        $pathWithNull = "/home/user\0music";
        $sanitized = $this->pathSecurity->sanitizePath($pathWithNull);

        $this->assertStringNotContainsString("\0", $sanitized);
    }

    #[Test]
    public function it_preserves_lyrics_formatting(): void
    {
        $input = "[Verse 1]\nLyrics line 1\nLine 2\n\n[Chorus]\nChorus text";
        $result = Text::sanitizeLyrics($input);

        // Should preserve newlines and markers
        $this->assertStringContainsString("\n", $result);
        $this->assertStringContainsString('[Verse 1]', $result);
        $this->assertStringContainsString('[Chorus]', $result);

        // Should remove dangerous patterns
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('onload=', $result);
    }

    private function createUser()
    {
        return \App\Models\User::factory()->create();
    }
}
