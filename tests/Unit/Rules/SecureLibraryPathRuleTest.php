<?php

namespace Tests\Unit\Rules;

use App\Rules\SecureLibraryPathRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecureLibraryPathRuleTest extends TestCase
{
    private SecureLibraryPathRule $rule;

    protected function setUp(): void
    {
        parent::setUp();

        // Set default config for testing
        Config::set('scanner.security.allowed_base_paths', ['/home', '/media', '/mnt', '/Users', '/Volumes']);
        Config::set('scanner.security.max_directory_depth', 20);

        $this->rule = new SecureLibraryPathRule();
    }

    #[Test]
    public function it_passes_for_valid_path_within_allowed_directory(): void
    {
        $result = $this->rule->passes('path', '/home/user/music');

        $this->assertTrue($result === true || $result === true);
    }

    #[Test]
    public function it_fails_for_path_traversal(): void
    {
        $result = $this->rule->passes('path', '/home/user/../../../etc/passwd');

        $this->assertNotTrue($result);
    }

    #[Test]
    public function it_fails_for_path_outside_allowed_directories(): void
    {
        $result = $this->rule->passes('path', '/etc/passwd');

        $this->assertNotTrue($result);
    }

    #[Test]
    public function it_fails_for_excessive_depth(): void
    {
        // Create path exceeding 20 levels
        $deepPath = '/home/' . implode('/', array_fill(0, 25, 'level'));

        $result = $this->rule->passes('path', $deepPath);

        $this->assertNotTrue($result);
    }

    #[Test]
    public function it_returns_correct_error_message(): void
    {
        $message = $this->rule->message();

        $this->assertStringContainsString('within:', $message);
        $this->assertStringContainsString('depth 20', $message);
        $this->assertStringContainsString('readable', $message);
    }
}
