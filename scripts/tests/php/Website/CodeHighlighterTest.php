<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests\Website;

use Baander\Tooling\Website\CodeHighlighter;
use PHPUnit\Framework\TestCase;

final class CodeHighlighterTest extends TestCase
{
    public function testPreservesModernPhpAndIncompleteFragments(): void
    {
        $sources = [
            <<<'SOURCE'
<?php
#[Route('/music')]
enum State: string { case Ready = 'ready'; }
final class Track {
    public string $name { get => $this->name; set => trim($value); }
}
$result = $track |> normalize(...) |> strtoupper(...);
$copy = clone($track, ['name' => 'Copied']);
// This remains source text.
$count = 42 + 1.5;
SOURCE,
            <<<'SOURCE'
    $text = "<script>alert('x')</script> {$name}";
    $html = '<span title="&">hello</span>';
    return $text |> trim(...);

SOURCE,
            <<<'SOURCE'
<?php
$text = <<<TEXT
Hello {$name} <a>&
TEXT;
$literal = <<<'TEXT'
Do not interpolate $name <>
TEXT;
SOURCE,
            "<article><?php echo \$name; ?><strong>&</strong><?= 'yes' ?></article>\n",
            "function unfinished(\n    string \$value,\n",
            "\$incomplete = 'unclosed <tag>\n",
            '',
        ];
        foreach ($sources as $source) {
            $html = new CodeHighlighter()->highlight($source);
            self::assertSame($source, html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            self::assertDoesNotMatchRegularExpression('/<(?!\/?span(?: |>)|$)/', $html);
        }
    }

    public function testEmitsNativeTokenRolesAndNoSyntheticTag(): void
    {
        $html = new CodeHighlighter()->highlight("// comment\nfunction greet(string \$name): string { return trim(\$name) |> strtoupper(...); }\n\$count = 42; \$text = 'hello';");
        foreach (['keyword', 'string', 'number', 'comment', 'variable', 'operator', 'function', 'type'] as $role) {
            self::assertStringContainsString('class="tok-' . $role . '"', $html);
        }
        self::assertStringNotContainsString('&lt;?php', $html);
        self::assertStringContainsString('<span class="tok-operator">|&gt;</span>', $html);
    }

    public function testEscapesInlineHtmlWithoutIntroducingMarkup(): void
    {
        $source = "<?php echo '<img src=x onerror=alert(1)>'; ?>\n<script>alert('x')</script>\n";
        $html = new CodeHighlighter()->highlight($source);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertSame($source, html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
