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

    public function testBorrowedManualBlocksReachThePublicPageAsTheManualPageRendersThem(): void
    {
        $this->source("# Manual\n\n```php\n\$name |> trim(...);\n```\n\n```json\ntrue\n```\n\n```mermaid\nflowchart LR\n    accTitle: A to B\n    accDescr: A leads to B.\nA --> B\n```\n\n```mermaid\nflowchart TB\n    accTitle: C to D\n    accDescr {\n        C leads to D.\n    }\nC --> D\n```\n");
        file_put_contents($this->root . '/ui/site/templates/feature.php', '<?php echo json_encode($data, JSON_THROW_ON_ERROR);');
        $this->builder()->build(
            renderBlocks: static fn(array $blocks, string $staging): array => array_combine(
                array_column($blocks, 'id'),
                array_map(static fn(array $block): string => '<figure class="rendered">' . $block['language'] . ' ' . $block['ordinal'] . '</figure>', $blocks),
            ),
            publicPages: [$this->publicPage(
                ['blocks' => [
                    ['manual' => 'README.md', 'contains' => 'A --> B', 'into' => ['feature', 'hero']],
                    ['manual' => 'README.md', 'contains' => 'C --> D', 'into' => ['feature', 'tour', 0, 'figure']],
                    ['manual' => 'README.md', 'contains' => 'trim(...)', 'into' => ['feature', 'tour', 1, 'figure']],
                    ['manual' => 'README.md', 'contains' => 'true', 'into' => ['feature', 'tour', 2, 'figure']],
                ]],
                ['feature' => ['hero' => ['contains' => 'A --> B'], 'tour' => [['figure' => []], ['figure' => []], ['id' => 'kept', 'figure' => []]]]],
            )],
        );
        $data = json_decode((string) file_get_contents($this->root . '/var/website/features/console/index.html'), true, flags: JSON_THROW_ON_ERROR);
        $manual = (string) file_get_contents($this->root . '/var/website/docs/index.html');
        self::assertSame('<figure class="rendered">mermaid 3</figure>', $data['feature']['hero']['html']);
        self::assertSame('A --> B', $data['feature']['hero']['contains']);
        self::assertSame('<figure class="rendered">mermaid 4</figure>', $data['feature']['tour'][0]['figure']['html']);
        self::assertStringContainsString('<span class="tok-operator">|&gt;</span>', $data['feature']['tour'][1]['figure']['html']);
        self::assertSame('<figure class="rendered">json 2</figure>', $data['feature']['tour'][2]['figure']['html']);
        self::assertSame('kept', $data['feature']['tour'][2]['id']);
        foreach ([$data['feature']['hero'], ...array_column($data['feature']['tour'], 'figure')] as $figure) {
            self::assertStringContainsString($figure['html'], $manual);
        }
    }

    public function testBorrowedBlockWithoutABlockRendererIsTheManualPagesEscapedCode(): void
    {
        $this->source("# Manual\n\n```text\nphp bin/console app:scan <library>\n```\n");
        file_put_contents($this->root . '/ui/site/templates/feature.php', '<?php echo $data["feature"]["hero"]["html"];');
        $this->builder()->build(publicPages: [$this->publicPage(
            ['blocks' => [['manual' => 'README.md', 'contains' => 'app:scan', 'into' => ['feature', 'hero']]]],
            ['feature' => ['hero' => []]],
        )]);
        $block = (string) file_get_contents($this->root . '/var/website/features/console/index.html');
        self::assertStringContainsString('app:scan &lt;library&gt;', $block);
        self::assertStringContainsString($block, (string) file_get_contents($this->root . '/var/website/docs/index.html'));
    }

    public function testManualBlockReferenceMustMatchExactlyOneBlockOfAnExistingPage(): void
    {
        $this->source("# Manual\n\n```json\ntrue\n```\n\n```json\n[true]\n```\n");
        $message = $this->failedReferences(['blocks' => [
            ['manual' => 'README.md', 'contains' => 'false', 'into' => ['feature', 'hero']],
            ['manual' => 'README.md', 'contains' => 'true', 'into' => ['feature', 'hero']],
            ['manual' => 'part-1-operator-guide/absent.md', 'contains' => 'true', 'into' => ['feature', 'hero']],
        ]]);
        self::assertSame(implode("\n", [
            'ui/site/features/console.php: no block of README.md contains "false"',
            'ui/site/features/console.php: 2 blocks of README.md contain "true"; a manual block reference matches exactly one',
            'ui/site/features/console.php: manual block reference names no manual page: part-1-operator-guide/absent.md',
        ]), $message);
    }

    public function testBorrowedDiagramMustStateItsTitleAndDescription(): void
    {
        $this->source("# Manual\n\n```mermaid\nflowchart LR\n    accDescr: A leads to B.\nA --> B\n```\n\n```mermaid\nflowchart LR\n    accTitle: C to D\nC --> D\n```\n\n```mermaid\nflowchart LR\nE --> F\n```\n");
        $message = $this->failedReferences(['blocks' => [
            ['manual' => 'README.md', 'contains' => 'A --> B', 'into' => ['feature', 'hero']],
            ['manual' => 'README.md', 'contains' => 'C --> D', 'into' => ['feature', 'hero']],
            ['manual' => 'README.md', 'contains' => 'E --> F', 'into' => ['feature', 'hero']],
        ]]);
        self::assertSame(implode("\n", [
            'ui/site/features/console.php: the diagram of README.md that contains "A --> B" has no accTitle',
            'ui/site/features/console.php: the diagram of README.md that contains "C --> D" has no accDescr',
            'ui/site/features/console.php: the diagram of README.md that contains "E --> F" has no accTitle and no accDescr',
        ]), $message);
    }

    public function testManualLinkMustNameAnExistingPageAndHeading(): void
    {
        $this->source("# Manual\n\n## Install\n\n```json\ntrue\n```\n");
        $message = $this->failedReferences(['links' => ['/docs/', '/docs/#install', '/docs/#absent', '/docs/absent/', '/docs/operator-guide/']]);
        self::assertSame(implode("\n", [
            'ui/site/features/console.php: manual link /docs/#absent names a heading its page does not have',
            'ui/site/features/console.php: manual link /docs/absent/ names no manual page',
        ]), $message);
    }

    /**
     * Builds with one public page whose manual references must fail, proves that no block was
     * rendered and that the published site is unchanged, and returns the failure message.
     *
     * @param array{links?: list<string>, blocks?: list<array{manual: string, contains: string, into: list<int|string>}>} $references
     */
    private function failedReferences(array $references): string
    {
        file_put_contents($this->root . '/ui/site/templates/feature.php', '<?php echo "feature";');
        $builder = $this->builder();
        $rendered = 0;
        $render = static function (array $blocks, string $staging) use (&$rendered): array {
            ++$rendered;
            return array_fill_keys(array_column($blocks, 'id'), '<figure>rendered</figure>');
        };
        $builder->build(renderBlocks: $render);
        $original = file_get_contents($this->root . '/var/website/docs/index.html');
        try {
            $builder->build(renderBlocks: $render, publicPages: [$this->publicPage($references, ['feature' => ['hero' => []]])]);
        } catch (\RuntimeException $error) {
            self::assertSame(1, $rendered, 'Blocks must not render when a manual reference fails.');
            self::assertSame($original, file_get_contents($this->root . '/var/website/docs/index.html'));
            self::assertSame([], glob($this->root . '/var/website-build-*'));

            return $error->getMessage();
        }
        self::fail('Unresolved manual references must fail the build.');
    }

    /**
     * @param array{links?: list<string>, blocks?: list<array{manual: string, contains: string, into: list<int|string>}>} $references
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function publicPage(array $references, array $data): array
    {
        return [
            'template' => 'feature', 'url' => '/features/console/', 'title' => 'Console',
            'description' => 'Run Bånder from the command line.',
            'references' => ['file' => 'ui/site/features/console.php', 'links' => [], 'blocks' => [], ...$references],
            'data' => $data,
        ];
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
