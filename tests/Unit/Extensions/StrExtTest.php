<?php

namespace Tests\Unit\Extensions;

use App\Extensions\StrExt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StrExtTest extends TestCase
{
    #[Test]
    public function it_removes_html_tags(): void
    {
        $input = '<script>alert("xss")</script>Hello';
        $result = StrExt::sanitize($input);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('</script>', $result);
    }

    #[Test]
    public function it_removes_xss_javascript_pattern(): void
    {
        $input = 'javascript:alert("xss")';
        $result = StrExt::sanitize($input);

        $this->assertStringNotContainsString('javascript:', $result);
    }

    #[Test]
    public function it_removes_xss_data_url_pattern(): void
    {
        $input = 'data:text/html,<script>alert(1)</script>';
        $result = StrExt::sanitize($input);

        $this->assertStringNotContainsString('data:text/html', $result);
    }

    #[Test]
    public function it_encodes_html_entities(): void
    {
        $input = '<> & "quotes"';
        $result = StrExt::sanitize($input);

        // Should encode special characters
        $this->assertStringContainsString('&lt;', $result);
        $this->assertStringContainsString('&gt;', $result);
        $this->assertStringContainsString('&quot;', $result);
    }

    #[Test]
    public function it_normalizes_unicode(): void
    {
        // Composed characters that could be represented differently
        $input = 'café naïve';
        $result = StrExt::sanitize($input);

        // Should be string without errors (basic check)
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    #[Test]
    public function it_preserves_basic_text_in_sanitize_metadata(): void
    {
        $input = 'Normal Song Title';
        $result = StrExt::sanitizeMetadata($input);

        $this->assertEquals('Normal Song Title', $result);
    }

    #[Test]
    public function it_removes_control_characters_in_sanitize_metadata(): void
    {
        $input = "Test\x00\x01\x02Title";
        $result = StrExt::sanitizeMetadata($input);

        $this->assertStringNotContainsString("\x00", $result);
        $this->assertStringNotContainsString("\x01", $result);
    }

    #[Test]
    public function it_normalizes_whitespace_in_sanitize_metadata(): void
    {
        $input = 'Test    Multiple     Spaces';
        $result = StrExt::sanitizeMetadata($input);

        $this->assertEquals('Test Multiple Spaces', $result);
    }

    #[Test]
    public function it_preserves_lyrics_formatting(): void
    {
        $input = "[Verse 1]\nLine 1\nLine 2\n\n[Chorus]\nChorus lyrics";
        $result = StrExt::sanitizeLyrics($input);

        // Should preserve newlines
        $this->assertStringContainsString("\n", $result);
        // Should preserve verse markers
        $this->assertStringContainsString('[Verse 1]', $result);
        $this->assertStringContainsString('[Chorus]', $result);
    }

    #[Test]
    public function it_removes_script_tags_from_lyrics(): void
    {
        $input = "[Verse 1]\n<script>alert('xss')</script>\nLyrics here";
        $result = StrExt::sanitizeLyrics($input);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('</script>', $result);
    }

    #[Test]
    public function it_removes_event_handlers_from_lyrics(): void
    {
        $input = 'Lyrics with onload="xss" content';
        $result = StrExt::sanitizeLyrics($input);

        $this->assertStringNotContainsString('onload=', $result);
    }

    #[Test]
    public function it_returns_null_for_null_input(): void
    {
        $this->assertNull(StrExt::sanitize(null));
        $this->assertNull(StrExt::sanitizeMetadata(null));
        $this->assertNull(StrExt::sanitizeLyrics(null));
    }

    #[Test]
    public function it_handles_empty_string(): void
    {
        $result = StrExt::sanitize('');
        $this->assertEquals('', $result);
    }

    #[Test]
    public function it_trims_whitespace(): void
    {
        $input = '  Test String  ';
        $result = StrExt::sanitize($input);

        $this->assertEquals('Test String', $result);
    }
}
