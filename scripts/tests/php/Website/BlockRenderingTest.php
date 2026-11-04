<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests\Website;

use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;
use Baander\Tooling\Website\BlockRendering;
use PHPUnit\Framework\TestCase;

final class BlockRenderingTest extends TestCase
{
    private string $root;
    private EngineExecutor $executor;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-block-rendering-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/ui/site/scripts', 0700, true);
        mkdir($this->root . '/stage', 0700, true);
        $this->executor = new EngineExecutor(ExecutionContext::bounded(15, getenv(), $this->root));
    }

    protected function tearDown(): void
    {
        $this->executor->close();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testReturnsSafeCodeAndCleansPrivateHelperManifest(): void
    {
        $this->helper(['sample' => ['kind' => 'code', 'html' => '<span class="tok-keyword">true</span> &amp; &lt;safe&gt;' . "\n"]]);
        $html = new BlockRendering($this->root, $this->executor, ':root {}')->render([$this->block("true & <safe>\n")], $this->root . '/stage');
        self::assertSame('<pre data-language="JSON"><code class="language-json"><span class="tok-keyword">true</span> &amp; &lt;safe&gt;' . "\n</code></pre>", $html['sample']);
        self::assertSame([], glob($this->root . '/var/website-render-*'));
        self::assertSame([], glob($this->root . '/stage/*'));
    }

    public function testRejectsChangedCodeAndUnsafeTokenMarkupWithPageContext(): void
    {
        foreach (['changed', '<img src=x onerror=alert(1)>', '<span class="tok-keyword" onclick="alert(1)">true</span>', '</span>true<span class="tok-keyword">'] as $html) {
            $this->helper(['sample' => ['kind' => 'code', 'html' => $html]]);
            try {
                new BlockRendering($this->root, $this->executor, ':root {}')->render([$this->block('true')], $this->root . '/stage');
                self::fail('Invalid helper HTML must fail.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('manual.md, block 3', $error->getMessage());
            }
            self::assertSame([], glob($this->root . '/var/website-render-*'));
        }
    }

    public function testCreatesSanitizedThemeAssetsAndAccessibleSourceDisclosure(): void
    {
        $directory = $this->root . '/ui/site/node_modules/@fontsource/inter/files';
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/inter-latin-400-normal.woff2', 'trusted-font');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 60"><text>A &amp; B</text><script>unsafe</script></svg>';
        $this->helper(['sample' => ['kind' => 'diagram', 'light' => $svg, 'dark' => $svg, 'width' => 100, 'height' => 60]]);
        $source = "flowchart LR\naccTitle: Music & playback\naccDescr: A to B\nA --> B\n";
        $block = $this->block($source);
        $block['language'] = 'mermaid';
        $html = new BlockRendering($this->root, $this->executor, ':root {}')->render([$block], $this->root . '/stage')['sample'];
        self::assertStringContainsString('Music &amp; playback</figcaption>', $html);
        self::assertSame(2, substr_count($html, 'alt="A to B"'));
        self::assertStringContainsString('data-pagefind-ignore="all"', $html);
        self::assertStringContainsString('tabindex="0" role="region"', $html);
        self::assertStringContainsString(htmlspecialchars($source, ENT_QUOTES), $html);
        foreach (['light', 'dark'] as $appearance) {
            $asset = file_get_contents($this->root . '/stage/assets/diagrams/sample-' . $appearance . '.svg');
            self::assertStringNotContainsString('<script', $asset);
            self::assertStringContainsString('base64,' . base64_encode('trusted-font'), $asset);
            self::assertStringContainsString('class="diagram-' . $appearance . '"', $html);
        }
    }

    public function testMissingHelperFailsWithContextAndCleansScratch(): void
    {
        try {
            new BlockRendering($this->root, $this->executor, ':root {}')->render([$this->block('true')], $this->root . '/stage');
            self::fail('Missing helper must fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('manual.md, block 3', $error->getMessage());
        }
        self::assertSame([], glob($this->root . '/var/website-render-*'));
    }

    /** @return array{id:string,source:string,language:string,page:string,ordinal:int} */
    private function block(string $source): array
    {
        return ['id' => 'sample', 'source' => $source, 'language' => 'json', 'page' => 'manual.md', 'ordinal' => 3];
    }

    /** @param array<string,array<string,mixed>> $result */
    private function helper(array $result): void
    {
        file_put_contents($this->root . '/ui/site/scripts/render-blocks.mjs', 'import {writeFileSync} from "node:fs"; writeFileSync(process.argv[3], ' . json_encode(json_encode($result, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR) . ');');
    }
}
