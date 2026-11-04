<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests\Website;

use Baander\Tooling\Website\WebsiteBuilder;
use PHPUnit\Framework\TestCase;

final class WebsiteBuilderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-website-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/docs-book/part-1-operator-guide/nested', 0700, true);
        mkdir($this->root . '/docs-book/part-2-developer-guide', 0700, true);
        mkdir($this->root . '/ui/site/templates', 0700, true);
        foreach (['page', 'home', '404'] as $template) {
            file_put_contents($this->root . '/ui/site/templates/' . $template . '.php', '<?php echo $page["html"];');
        }
        $this->source('README.md', "# Manual\n[Operator](part-1-operator-guide/README.md)");
        $this->source('part-1-operator-guide/README.md', "# Operator\n[Chapter](chapter.md)\n[Nested](nested/README.md)");
        $this->source('part-2-developer-guide/README.md', '# Developer');
        $this->source('part-1-operator-guide/chapter.md', "# Café\n## Repeat\n## Repeat\n[Section](#repeat-1)\n[Developer](../part-2-developer-guide/README.md#developer)\n[Repository](../../AGENTS.md)\n`[literal](missing.md)`");
        $this->source('part-1-operator-guide/nested/README.md', '# Nested');
        file_put_contents($this->root . '/AGENTS.md', '# Instructions');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testBuildsDirectoryUrlsAndValidatesAstLinksWithoutChangingCode(): void
    {
        $manifest = $this->builder()->build();
        $html = file_get_contents($this->root . '/var/website/docs/operator-guide/chapter/index.html');
        self::assertStringContainsString('id="café"', $html);
        self::assertStringContainsString('id="repeat-1"', $html);
        self::assertStringContainsString('href="/docs/developer-guide/#developer"', $html);
        self::assertStringContainsString('https://github.com/baander-app/baander/blob/abc123/AGENTS.md', $html);
        self::assertStringContainsString('[literal](missing.md)', $html);
        self::assertFileExists($this->root . '/var/website/docs/operator-guide/nested/index.html');
        self::assertCount(5, $manifest);
    }

    public function testBoundedContextsChapterFoldsItsPagesUnderOneNavigationEntry(): void
    {
        mkdir($this->root . '/docs-book/part-2-developer-guide/contexts', 0700, true);
        file_put_contents($this->root . '/ui/site/templates/page.php', '<?php echo json_encode($navigation, JSON_THROW_ON_ERROR);');
        $this->source('part-2-developer-guide/README.md', "# Developer\n[Guide](guide.md)\n[Contexts](contexts/README.md)");
        $this->source('part-2-developer-guide/guide.md', '# Guide');
        $this->source('part-2-developer-guide/contexts/README.md', "# Bounded Contexts\n[Guide](../guide.md)\n[Auth](auth.md)\n[Catalog](catalog.md#models)\n[Auth again](auth.md)");
        $this->source('part-2-developer-guide/contexts/auth.md', '# Auth');
        $this->source('part-2-developer-guide/contexts/catalog.md', "# Catalog\n## Models");
        $this->builder()->build();
        $navigation = json_decode((string) file_get_contents($this->root . '/var/website/docs/developer-guide/guide/index.html'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            ['url' => '/docs/developer-guide/', 'title' => 'Developer'],
            ['url' => '/docs/developer-guide/guide/', 'title' => 'Guide'],
            ['url' => '/docs/developer-guide/contexts/', 'title' => 'Bounded Contexts', 'children' => [
                ['url' => '/docs/developer-guide/contexts/auth/', 'title' => 'Auth'],
                ['url' => '/docs/developer-guide/contexts/catalog/', 'title' => 'Catalog'],
            ]],
        ], $navigation['developer']);
        foreach ($navigation['operator'] as $entry) {
            self::assertArrayNotHasKey('children', $entry, 'Only opted-in chapter indexes fold out');
        }
    }

    public function testFailedRebuildRetainsExistingArtifactAndSource(): void
    {
        $this->builder()->build();
        $previous = file_get_contents($this->root . '/var/website/docs/index.html');
        $this->source('README.md', '# Changed' . "\n[Missing](missing.md)");
        try {
            $this->builder()->build();
            self::fail('Missing links must fail the build.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('missing.md', $error->getMessage());
        }
        self::assertSame($previous, file_get_contents($this->root . '/var/website/docs/index.html'));
        self::assertStringContainsString('# Changed', file_get_contents($this->root . '/docs-book/README.md'));
    }

    public function testPublicPageReceivesDataWithoutJoiningManualManifest(): void
    {
        file_put_contents($this->root . '/ui/site/templates/baac.php', '<?php echo htmlspecialchars($data["name"]);');
        $pages = $this->builder()->build(publicPages: [[
            'template' => 'baac', 'url' => '/baac/', 'title' => 'bAAC',
            'description' => 'Codec measurements', 'data' => ['name' => 'bAAC'],
        ]]);
        self::assertCount(5, $pages);
        self::assertSame('bAAC', file_get_contents($this->root . '/var/website/baac/index.html'));
    }

    public function testInvalidPublicRouteKeepsPreviousArtifact(): void
    {
        $this->builder()->build();
        $previous = file_get_contents($this->root . '/var/website/docs/index.html');
        foreach (['/', '/docs/other/', '/../outside/'] as $url) {
            try {
                $this->builder()->build(publicPages: [[
                    'template' => 'home', 'url' => $url, 'title' => 'Invalid',
                    'description' => '', 'data' => [],
                ]]);
                self::fail('Invalid public route must fail: ' . $url);
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('public page URL', $error->getMessage());
            }
            self::assertSame($previous, file_get_contents($this->root . '/var/website/docs/index.html'));
        }
    }

    public function testRejectsUnsafeLinksAndMissingFragments(): void
    {
        foreach (['javascript:alert(1)', '#absent'] as $link) {
            $this->source('README.md', '# Manual' . "\n[Unsafe]($link)");
            try {
                $this->builder()->build();
                self::fail('Invalid link must fail: ' . $link);
            } catch (\RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testRejectsDuplicateRoutes(): void
    {
        $this->source('part-1-operator-guide/nested.md', '# Collision');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate');
        $this->builder()->build();
    }

    public function testCopiesDeclaredAssetsAndRebuildsMaintainedContent(): void
    {
        file_put_contents($this->root . '/docs-book/part-1-operator-guide/cover.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $this->source('part-1-operator-guide/chapter.md', "# Picture\n![Cover](cover.svg)\n[Guide root](README.md)\n[Directory](nested/)");
        $this->builder()->build();
        $artifact = $this->root . '/var/website/docs/operator-guide/chapter/index.html';
        self::assertStringContainsString('src="/assets/manual/part-1-operator-guide/cover.svg"', file_get_contents($artifact));
        self::assertFileExists($this->root . '/var/website/assets/manual/part-1-operator-guide/cover.svg');
        $this->source('part-1-operator-guide/chapter.md', "# Updated content\n<script>alert(1)</script>\n| Key | Value |\n| --- | --- |\n| One | Two |");
        $this->builder()->build();
        self::assertStringContainsString('Updated content', file_get_contents($artifact));
        self::assertStringContainsString('&lt;script&gt;', file_get_contents($artifact));
        self::assertStringContainsString('<table>', file_get_contents($artifact));
        self::assertFileDoesNotExist($this->root . '/var/website/assets/manual/part-1-operator-guide/cover.svg');
    }

    public function testMapsRepositoryFilesAndDirectoriesWithoutCopyingThem(): void
    {
        mkdir($this->root . '/scripts');
        file_put_contents($this->root . '/scripts/settings.json', '{}');
        file_put_contents($this->root . '/scripts/build.php', '<?php');
        $this->source('README.md', "# Manual\n[JSON](../scripts/settings.json)\n[Script](../scripts/build.php)\n[Directory](../scripts/)");
        $this->builder()->build();
        $html = file_get_contents($this->root . '/var/website/docs/index.html');
        self::assertStringContainsString('/blob/abc123/scripts/settings.json', $html);
        self::assertStringContainsString('/blob/abc123/scripts/build.php', $html);
        self::assertStringContainsString('/tree/abc123/scripts', $html);
        self::assertFileDoesNotExist($this->root . '/var/website/scripts');
    }

    public function testRejectsEscapingSourcesAndOutputLinksBeforeWriting(): void
    {
        symlink('/etc/passwd', $this->root . '/docs-book/leak.md');
        try {
            $this->builder()->build();
            self::fail('Escaping source must fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('escaping', $error->getMessage());
        }
        unlink($this->root . '/docs-book/leak.md');
        mkdir($this->root . '/var');
        symlink($this->root . '/docs-book', $this->root . '/var/website');
        try {
            $this->builder()->build();
            self::fail('Output symlink must fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('owned directory', $error->getMessage());
        }
        self::assertFileExists($this->root . '/docs-book/README.md');
    }

    public function testRejectsSourceOutputOverlap(): void
    {
        mkdir($this->root . '/var');
        rename($this->root . '/docs-book', $this->root . '/var/manual');
        symlink($this->root . '/var/manual', $this->root . '/docs-book');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('overlap');
        $this->builder()->build();
    }

    public function testIndexFailureAndBrokenTemplateLinksRetainPreviousArtifact(): void
    {
        $this->builder()->build();
        $artifact = $this->root . '/var/website/docs/index.html';
        $before = file_get_contents($artifact);
        try {
            $this->builder()->build(static function (string $staging, array $pages): void {
                self::assertFileExists($staging . '/docs/index.html');
                self::assertCount(5, $pages);
                throw new \RuntimeException('Search index failed');
            });
            self::fail('Index failure must fail publication.');
        } catch (\RuntimeException $error) {
            self::assertSame('Search index failed', $error->getMessage());
        }
        self::assertSame($before, file_get_contents($artifact));
        file_put_contents($this->root . '/ui/site/templates/home.php', '<a href="/missing/">Missing</a>');
        try {
            $this->builder()->build();
            self::fail('Broken template link must fail publication.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Missing artifact target', $error->getMessage());
        }
        self::assertSame($before, file_get_contents($artifact));
    }

    private function source(string $path, string $content): void
    {
        file_put_contents($this->root . '/docs-book/' . $path, $content);
    }

    private function builder(): WebsiteBuilder
    {
        return new WebsiteBuilder($this->root, 'https://github.com/baander-app/baander/tree/abc123');
    }
}
