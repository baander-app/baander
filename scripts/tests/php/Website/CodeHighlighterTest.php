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

    public function testClassifiesModernPhpNamesByTheirRoleInTheCode(): void
    {
        $source = <<<'SOURCE'
<?php
#[Route('/music', methods: ['GET'])]
enum State: string { case Ready = 'ready'; }
final readonly class Track extends Base implements Countable {
    public const string KIND = 'track';
    public string $name { get => $this->name; set => trim($value); }
    public function __construct(private ?Uuid $id = null, public array $tags = []) {}
    public static function create(): static { return new Track(Uuid::generate(), tags: [State::Ready]); }
}
echo $track?->title, PHP_EOL, Track::KIND, true;
/** @return list<string> */
SOURCE;
        $html = new CodeHighlighter()->highlight($source);
        $expected = [
            ['type', 'Route'], ['property', 'methods'], ['type', 'State'], ['constant', 'Ready'],
            ['type', 'Track'], ['type', 'Base'], ['type', 'Countable'], ['type', 'string'], ['constant', 'KIND'],
            ['keyword', 'get'], ['keyword', 'set'], ['property', 'name'], ['function', 'trim'],
            ['function', '__construct'], ['type', 'Uuid'], ['constant', 'null'], ['type', 'array'],
            ['type', 'static'], ['function', 'generate'], ['property', 'tags'], ['property', 'title'],
            ['constant', 'PHP_EOL'], ['constant', 'true'], ['keyword', '@return'],
        ];
        foreach ($expected as [$role, $text]) {
            self::assertStringContainsString('<span class="tok-' . $role . '">' . $text . '</span>', $html, $text . ' should be ' . $role);
        }
        self::assertStringNotContainsString('<span class="tok-function">Track</span>', $html, 'Instantiation names a type, not a function call');
        self::assertSame($source, html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
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
