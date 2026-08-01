<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\DocGenerator;

use App\Command\DocGenerator\MarkdownConverter;
use PHPUnit\Framework\TestCase;

final class MarkdownConverterTest extends TestCase
{
    private string $docsBookDir;
    private string $outputDir;
    private MarkdownConverter $converter;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/baander_mdconv_' . bin2hex(random_bytes(6));
        $this->docsBookDir = $root . '/docs-book';
        $this->outputDir = $root . '/out';
        $this->converter = new MarkdownConverter();

        // Root README becomes index.html and links into the operator guide.
        $this->writeFile(
            $this->docsBookDir . '/README.md',
            "# Welcome\n\nSee the [intro](part-1-operator-guide/intro.md).\n",
        );

        // part-1 is remapped to operator-guide. Tests relative ../ links,
        // sibling links, fragment links, and external http links.
        $this->writeFile(
            $this->docsBookDir . '/part-1-operator-guide/intro.md',
            "# Intro Title\n\n"
            . "[back](../README.md)\n\n"
            . "[details](details.md)\n\n"
            . "[frag](details.md#section)\n\n"
            . "[ext](https://example.com/page.md)\n",
        );

        // part-2 is remapped to developer-guide; its README becomes the dir index.
        $this->writeFile(
            $this->docsBookDir . '/part-2-developer-guide/README.md',
            "# Developer Guide\n\nContent here.\n",
        );

        // Non-markdown files must be ignored.
        $this->writeFile(
            $this->docsBookDir . '/part-1-operator-guide/image.png',
            'binary',
        );

        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->docsBookDir);
        $this->removeDirectory($this->outputDir);
        @rmdir(dirname($this->docsBookDir));
    }

    public function testConvertReturnsRelativeHtmlPathsForEveryMarkdownFile(): void
    {
        $pages = $this->converter->convert($this->docsBookDir, $this->outputDir, 'phpdoc');

        sort($pages);

        $this->assertSame(
            ['developer-guide/index.html', 'index.html', 'operator-guide/intro.html'],
            $pages,
        );
    }

    public function testRootReadmeIsWrittenToIndexHtmlWithZeroDepthAssetPaths(): void
    {
        $this->converter->convert($this->docsBookDir, $this->outputDir, 'phpdoc');

        $html = file_get_contents($this->outputDir . '/index.html');
        \assert($html !== false);

        // Title extracted from the H1.
        $this->assertStringContainsString('<title>Welcome — Bånder Docs</title>', $html);
        // Root depth: no leading ../ for css and the home nav link.
        $this->assertStringContainsString('<link rel="stylesheet" href="css/style.css">', $html);
        $this->assertStringContainsString('<a href="index.html">Bånder Docs</a>', $html);
        // The rewritten markdown link is rendered by Parsedown as an anchor;
        // the directory map remaps the operator-guide link target.
        $this->assertStringContainsString('<a href="operator-guide/intro.html">intro</a>', $html);
    }

    public function testOperatorGuideIntroIsRemappedAndRewritesRelativeLinks(): void
    {
        $this->converter->convert($this->docsBookDir, $this->outputDir, 'phpdoc');

        $html = file_get_contents($this->outputDir . '/operator-guide/intro.html');
        \assert($html !== false);

        // Title extracted and escaped.
        $this->assertStringContainsString('<title>Intro Title — Bånder Docs</title>', $html);
        // One level deep: assets get a single ../ prefix.
        $this->assertStringContainsString('<link rel="stylesheet" href="../css/style.css">', $html);
        $this->assertStringContainsString('<a href="../index.html">Bånder Docs</a>', $html);

        // ../README.md resolves to the root README (no dir prefix), then to
        // README.html. Parsedown renders the rewritten link as an anchor.
        $this->assertStringContainsString('<a href="README.html">back</a>', $html);
        // Sibling link keeps directory mapping and gains .html.
        $this->assertStringContainsString('<a href="details.html">details</a>', $html);
        // Fragment is preserved.
        $this->assertStringContainsString('<a href="details.html#section">frag</a>', $html);
        // External http link survives unchanged through Parsedown.
        $this->assertStringContainsString('<a href="https://example.com/page.md">ext</a>', $html);
    }

    public function testDeveloperGuideReadmeBecomesDirectoryIndex(): void
    {
        $this->converter->convert($this->docsBookDir, $this->outputDir, 'phpdoc');

        $this->assertFileExists($this->outputDir . '/developer-guide/index.html');

        $html = file_get_contents($this->outputDir . '/developer-guide/index.html');
        \assert($html !== false);

        $this->assertStringContainsString('<title>Developer Guide — Bånder Docs</title>', $html);
        $this->assertStringContainsString('<link rel="stylesheet" href="../css/style.css">', $html);
    }

    public function testNonMarkdownFilesAreSkipped(): void
    {
        $pages = $this->converter->convert($this->docsBookDir, $this->outputDir, 'phpdoc');

        $this->assertNotContains('operator-guide/image.png', $pages);
        $this->assertNotContains('operator-guide/image.html', $pages);
        $this->assertFileDoesNotExist($this->outputDir . '/operator-guide/image.html');
    }

    public function testNavLinksIncludeTheStandardSections(): void
    {
        $this->converter->convert($this->docsBookDir, $this->outputDir, 'phpdoc');

        $html = file_get_contents($this->outputDir . '/index.html');
        \assert($html !== false);

        $this->assertStringContainsString('API Reference', $html);
        $this->assertStringContainsString('Domain Models', $html);
        $this->assertStringContainsString('Architecture', $html);
        $this->assertStringContainsString('Contexts', $html);
    }

    private function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($path);
    }
}
