<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests\Website;

use Baander\Tooling\Website\WebsiteBuilder;
use PHPUnit\Framework\TestCase;

final class BuilderBlockRenderingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-builder-blocks-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/docs-book/part-1-operator-guide', 0700, true);
        mkdir($this->root . '/docs-book/part-2-developer-guide', 0700, true);
        mkdir($this->root . '/ui/site/templates', 0700, true);
        foreach (['page', 'home', '404'] as $name) {
            file_put_contents($this->root . '/ui/site/templates/' . $name . '.php', '<?php echo $page["html"];');
        }
        file_put_contents($this->root . '/docs-book/part-1-operator-guide/README.md', '# Operator');
        file_put_contents($this->root . '/docs-book/part-2-developer-guide/README.md', '# Developer');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testCollectsManifestBeforeArticleRenderingAndHighlightsPhpNatively(): void
    {
        $this->source("# Manual\n\n```php\n\$name |> trim(...);\n```\n\n```json\ntrue\n```\n\n```mermaid\nflowchart LR\nA --> B\n```\n");
        $observed = [];
        $afterRender = false;
        $this->builder()->build(
            function (string $staging) use (&$afterRender): void {
                self::assertFileExists($staging . '/docs/index.html');
                $afterRender = true;
            },
            static function (array $blocks, string $staging) use (&$observed): array {
                self::assertDirectoryExists($staging);
                self::assertFileDoesNotExist($staging . '/docs/index.html');
                $observed = $blocks;
                return [$blocks[0]['id'] => '<pre><code class="language-json"><span class="tok-keyword">true</span>' . "\n</code></pre>", $blocks[1]['id'] => '<figure class="diagram">A to B</figure>'];
            },
        );
        self::assertTrue($afterRender);
        self::assertCount(2, $observed);
        self::assertSame(['json', 'mermaid'], array_column($observed, 'language'));
        self::assertSame([2, 3], array_column($observed, 'ordinal'));
        self::assertSame("true\n", $observed[0]['source']);
        self::assertSame('README.md', $observed[0]['page']);
        $html = file_get_contents($this->root . '/var/website/docs/index.html');
        self::assertStringContainsString('<span class="tok-operator">|&gt;</span>', $html);
        self::assertStringContainsString('<span class="tok-keyword">true</span>', $html);
        self::assertStringContainsString('<figure class="diagram">A to B</figure>', $html);
        self::assertStringNotContainsString('&lt;?php', $html);
    }

    public function testRendererFailureRetainsPreviousArtifactAndCleansStaging(): void
    {
        $this->source("# Original\n\n```json\ntrue\n```\n");
        $builder = $this->builder();
        $builder->build();
        $original = file_get_contents($this->root . '/var/website/docs/index.html');
        foreach ([static fn(array $blocks, string $staging): array => [], static fn(array $blocks, string $staging): array => ['unknown' => '<pre>wrong</pre>']] as $callback) {
            try {
                $builder->build(renderBlocks: $callback);
                self::fail('Incomplete or unknown results must fail.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('README.md, block 1', $error->getMessage());
            }
            self::assertSame($original, file_get_contents($this->root . '/var/website/docs/index.html'));
            self::assertSame([], glob($this->root . '/var/website-build-*'));
        }
    }

    public function testMermaidRequiresHelperAndPreservesPreviousArtifact(): void
    {
        $this->source('# Original');
        $builder = $this->builder();
        $builder->build();
        $original = file_get_contents($this->root . '/var/website/docs/index.html');
        $this->source("# Diagram\n\n```mermaid\nflowchart LR\nA --> B\n```\n");
        try {
            $builder->build();
            self::fail('Mermaid must not publish as source-only code.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('README.md, block 1', $error->getMessage());
        }
        self::assertSame($original, file_get_contents($this->root . '/var/website/docs/index.html'));
        self::assertSame([], glob($this->root . '/var/website-build-*'));
    }

    public function testRebuildParsesNewNodesAndRetainsDeterministicBlockIdentifiers(): void
    {
        $builder = $this->builder();
        $ids = [];
        $render = static function (array $blocks, string $staging) use (&$ids): array {
            $ids[] = $blocks[0]['id'];
            return [$blocks[0]['id'] => '<pre><code>' . htmlspecialchars($blocks[0]['source']) . '</code></pre>'];
        };
        $this->source("# Manual\n\n```unknown\nfirst & value\n```\n");
        $builder->build(renderBlocks: $render);
        $this->source("# Manual\n\n```unknown\nsecond < value\n```\n");
        $builder->build(renderBlocks: $render);
        self::assertSame($ids[0], $ids[1]);
        $html = file_get_contents($this->root . '/var/website/docs/index.html');
        self::assertStringContainsString('second &lt; value', $html);
        self::assertStringNotContainsString('first', $html);
    }

    private function source(string $markdown): void
    {
        file_put_contents($this->root . '/docs-book/README.md', $markdown);
    }

    private function builder(): WebsiteBuilder
    {
        return new WebsiteBuilder($this->root, 'https://github.com/baander-app/baander/tree/abc123');
    }
}
