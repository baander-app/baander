<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests\Website;

use Baander\Tooling\Website\CodeBlock;
use PHPUnit\Framework\TestCase;

final class CodeBlockTest extends TestCase
{
    public function testLabelsNamedLanguagesOutsideTheCodeText(): void
    {
        $html = CodeBlock::html('php', '<span class="tok-keyword">echo</span> 1;');
        self::assertSame('<pre data-language="PHP"><code class="language-php"><span class="tok-keyword">echo</span> 1;</code></pre>', $html);
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($html));
        self::assertSame('echo 1;', $document->getElementsByTagName('code')->item(0)?->textContent);
    }

    public function testUsesReadableNamesAndFallsBackToTheFenceName(): void
    {
        foreach (['bash' => 'Bash', 'env' => '.env', 'ts' => 'TypeScript', 'tsx' => 'TSX', 'yml' => 'YAML', 'toml' => 'TOML'] as $language => $label) {
            self::assertSame($label, CodeBlock::label($language));
        }
        self::assertNull(CodeBlock::label(''));
        self::assertStringStartsWith('<pre><code class="language-">', CodeBlock::html('', 'plain'));
    }

    public function testEscapesUnexpectedFenceNames(): void
    {
        $html = CodeBlock::html('a"b<c', 'x');
        self::assertStringContainsString('data-language="A&quot;B&lt;C"', $html);
        self::assertStringContainsString('class="language-a&quot;b&lt;c"', $html);
    }
}
