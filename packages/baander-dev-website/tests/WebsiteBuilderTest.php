<?php

declare(strict_types=1);

namespace Baander\DevWebsite\Tests;

use Baander\DevWebsite\Metadata\SourceHistory;
use Baander\DevWebsite\WebsiteBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
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
        mkdir($this->root . '/docs-book/part-2-developer-guide/testing', 0700, true);
        file_put_contents($this->root . '/ui/site/templates/page.php', '<?php echo json_encode($navigation, JSON_THROW_ON_ERROR);');
        $this->source('part-2-developer-guide/README.md', "# Developer\n[Guide](guide.md)\n[Runners shortcut](testing/backend-runners.md)\n[Testing](testing/README.md)\n[Contexts](contexts/README.md)");
        $this->source('part-2-developer-guide/guide.md', '# Guide');
        $this->source('part-2-developer-guide/testing/README.md', "# Testing\n[Auth cross-link](../contexts/auth.md)\n## Backend\n[Runners](backend-runners.md)");
        $this->source('part-2-developer-guide/testing/backend-runners.md', '# Backend Runners');
        $this->source('part-2-developer-guide/contexts/README.md', "# Bounded Contexts\n[Guide](../guide.md)\n[Auth](auth.md)\n[Catalog](catalog.md#models)\n[Auth again](auth.md)");
        $this->source('part-2-developer-guide/contexts/auth.md', '# Auth');
        $this->source('part-2-developer-guide/contexts/catalog.md', "# Catalog\n## Models");
        $this->builder()->build();
        $navigation = json_decode((string) file_get_contents($this->root . '/var/website/docs/developer-guide/guide/index.html'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            ['url' => '/docs/developer-guide/', 'title' => 'Developer'],
            ['url' => '/docs/developer-guide/guide/', 'title' => 'Guide'],
            ['url' => '/docs/developer-guide/testing/', 'title' => 'Testing', 'children' => [
                ['url' => '/docs/developer-guide/testing/backend-runners/', 'title' => 'Backend Runners', 'group' => 'Backend'],
            ]],
            ['url' => '/docs/developer-guide/contexts/', 'title' => 'Bounded Contexts', 'children' => [
                ['url' => '/docs/developer-guide/contexts/auth/', 'title' => 'Auth'],
                ['url' => '/docs/developer-guide/contexts/catalog/', 'title' => 'Catalog'],
            ]],
        ], $navigation['developer']);
        foreach ($navigation['operator'] as $entry) {
            self::assertArrayNotHasKey('children', $entry, 'Only opted-in chapter indexes fold out');
        }
    }

    public function testCliReferenceFoldsItsCommandsUnderTheirSectionHeadings(): void
    {
        mkdir($this->root . '/docs-book/part-1-operator-guide/commands', 0700, true);
        file_put_contents($this->root . '/ui/site/templates/page.php', '<?php echo json_encode($navigation, JSON_THROW_ON_ERROR);');
        $this->source('part-1-operator-guide/README.md', "# Operator\n[Chapter](chapter.md)\n[CLI](commands/README.md)");
        $this->source('part-1-operator-guide/commands/README.md', "# CLI Commands\nRun them from [Chapter](../chapter.md).\n[Ungrouped](app-about.md)\n## Auth & Users\n[app:user:create](app-user-create.md)\n## Server\n[app:settings](app-settings.md)");
        foreach (['app-about' => 'app:about', 'app-user-create' => 'app:user:create', 'app-settings' => 'app:settings'] as $file => $title) {
            $this->source('part-1-operator-guide/commands/' . $file . '.md', '# ' . $title);
        }
        $this->builder()->build();
        $navigation = json_decode((string) file_get_contents($this->root . '/var/website/docs/operator-guide/chapter/index.html'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['url' => '/docs/operator-guide/commands/', 'title' => 'CLI Commands', 'children' => [
            ['url' => '/docs/operator-guide/commands/app-about/', 'title' => 'app:about'],
            ['url' => '/docs/operator-guide/commands/app-user-create/', 'title' => 'app:user:create', 'group' => 'Auth & Users'],
            ['url' => '/docs/operator-guide/commands/app-settings/', 'title' => 'app:settings', 'group' => 'Server'],
        ]], $navigation['operator'][2]);
    }

    public function testWritesSitemapAndRobotsForEveryPublishedPage(): void
    {
        file_put_contents($this->root . '/ui/site/templates/extra.php', '<?php echo "extra";');
        $history = SourceHistory::fromGitLog("\x1e2026-11-04T10:00:00+00:00\n\ndocs-book/part-1-operator-guide/chapter.md\nui/site/templates/extra.php\n");
        $this->builder()->build(publicPages: [['template' => 'extra', 'url' => '/extra/', 'title' => 'Extra', 'description' => 'Extra page', 'data' => []]], history: $history);
        $document = new \DOMDocument();
        self::assertTrue($document->load($this->root . '/var/website/sitemap.xml'));
        $entries = [];
        foreach ($document->getElementsByTagName('url') as $url) {
            $entries[$url->getElementsByTagName('loc')->item(0)?->textContent] = $url->getElementsByTagName('lastmod')->item(0)?->textContent;
        }
        self::assertSame([
            'https://baander.app/' => null,
            'https://baander.app/docs/' => null,
            'https://baander.app/docs/developer-guide/' => null,
            'https://baander.app/docs/operator-guide/' => null,
            'https://baander.app/docs/operator-guide/chapter/' => '2026-11-04T10:00:00+00:00',
            'https://baander.app/docs/operator-guide/nested/' => null,
            'https://baander.app/extra/' => '2026-11-04T10:00:00+00:00',
        ], $entries);
        self::assertStringContainsString('Sitemap: https://baander.app/sitemap.xml', (string) file_get_contents($this->root . '/var/website/robots.txt'));
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
        file_put_contents($this->root . '/ui/site/templates/bac.php', '<?php echo htmlspecialchars($data["name"]);');
        $pages = $this->builder()->build(publicPages: [[
            'template' => 'bac', 'url' => '/bac/', 'title' => 'Bånder Audio Core',
            'description' => 'Codec measurements', 'data' => ['name' => 'Bånder Audio Core'],
        ]]);
        self::assertCount(5, $pages);
        self::assertSame('Bånder Audio Core', file_get_contents($this->root . '/var/website/bac/index.html'));
    }

    public function testPublicPageTrailReachesItsTitleAndBreadcrumbs(): void
    {
        file_put_contents(
            $this->root . '/ui/site/templates/feature.php',
            '<?php echo json_encode(["title" => $page["meta"]->title, "breadcrumbs" => $page["meta"]->breadcrumbs, "structured" => json_decode($page["meta"]->structuredData, true)], JSON_THROW_ON_ERROR);',
        );
        $this->builder()->build(publicPages: [[
            'template' => 'feature', 'url' => '/features/equalizer/', 'title' => 'Equalizer',
            'description' => 'Shape the sound of every song.',
            'trail' => [['name' => 'Features', 'url' => '/features/']], 'data' => [],
        ]]);
        $meta = json_decode((string) file_get_contents($this->root . '/var/website/features/equalizer/index.html'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Equalizer — Features — Bånder', $meta['title']);
        self::assertSame([['name' => 'Bånder', 'url' => '/'], ['name' => 'Features', 'url' => '/features/']], $meta['breadcrumbs']);
        self::assertSame(
            ['https://baander.app/', 'https://baander.app/features/', 'https://baander.app/features/equalizer/'],
            array_column($meta['structured']['@graph'][1]['itemListElement'], 'item'),
        );
    }

    public function testEveryTemplateReceivesTheDimensionsOfEachPictureWithBothFiles(): void
    {
        mkdir($this->root . '/ui/site/assets/screenshots', 0700, true);
        $fixtures = dirname(__DIR__, 3) . '/packages/baander-dev-website/tests/Fixtures/';
        copy($fixtures . 'picture-4x2.webp', $this->root . '/ui/site/assets/screenshots/music.webp');
        copy($fixtures . 'picture-4x2.webp', $this->root . '/ui/site/assets/screenshots/music-light.webp');
        copy($fixtures . 'picture-6x2.webp', $this->root . '/ui/site/assets/screenshots/dark-only.webp');
        foreach (['home', 'page', 'extra'] as $template) {
            file_put_contents($this->root . '/ui/site/templates/' . $template . '.php', '<?php echo json_encode($site["screenshots"], JSON_THROW_ON_ERROR);');
        }
        $this->builder()->build(publicPages: [['template' => 'extra', 'url' => '/extra/', 'title' => 'Extra', 'description' => 'Extra page', 'data' => []]]);
        foreach (['index.html', 'docs/index.html', 'extra/index.html'] as $artifact) {
            self::assertSame('{"music":{"width":4,"height":2}}', file_get_contents($this->root . '/var/website/' . $artifact), $artifact);
        }

        file_put_contents($this->root . '/ui/site/assets/screenshots/music.webp', 'not a picture');
        try {
            $this->builder()->build();
            self::fail('A picture whose dimensions cannot be read must fail the build.');
        } catch (\RuntimeException $error) {
            self::assertSame('Cannot read picture dimensions: ' . $this->root . '/ui/site/assets/screenshots/music.webp', $error->getMessage());
        }
        self::assertSame('{"music":{"width":4,"height":2}}', file_get_contents($this->root . '/var/website/index.html'));
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

    public function testReferenceSourceResolvesLinksFromItsOwnDirectory(): void
    {
        mkdir($this->root . '/packages/x', 0700, true);
        mkdir($this->root . '/deploy/preset', 0700, true);
        file_put_contents($this->root . '/packages/x/LICENSE', 'MIT');
        file_put_contents($this->root . '/NOTES.md', "# Notes\n## Licences\n[Licence](packages/x/LICENSE)\n[Same page](#licences)");
        file_put_contents($this->root . '/deploy/preset/README.md', "# Preset\n[Repeat](../../docs-book/part-1-operator-guide/chapter.md#repeat-1)\n[Notes](../../NOTES.md#licences)");
        $this->referenceTemplate();
        $this->builder()->build(publicPages: [self::reference('/notes/', 'NOTES.md'), self::reference('/preset/', 'deploy/preset/README.md')]);

        $notes = (string) file_get_contents($this->root . '/var/website/notes/index.html');
        self::assertStringContainsString('href="https://github.com/baander-app/baander/blob/abc123/packages/x/LICENSE"', $notes);
        self::assertStringContainsString('href="/notes/#licences"', $notes);
        $preset = (string) file_get_contents($this->root . '/var/website/preset/index.html');
        self::assertStringContainsString('href="/docs/operator-guide/chapter/#repeat-1"', $preset);
        self::assertStringContainsString('href="/notes/#licences"', $preset);
    }

    public function testManualLinkToAPublishedFileOpensItsPageAndMustNameOneOfItsHeadings(): void
    {
        file_put_contents($this->root . '/TERMS.md', "# Terms\n## Identity\n### Public ID\nA short identifier.");
        $this->referenceTemplate();
        $this->source('part-1-operator-guide/nested/README.md', "# Nested\n[Public ID](../../../TERMS.md#public-id)");
        $terms = [self::reference('/terms/', 'TERMS.md')];
        $this->builder()->build(publicPages: $terms);
        self::assertStringContainsString(
            'href="/terms/#public-id"',
            (string) file_get_contents($this->root . '/var/website/docs/operator-guide/nested/index.html'),
        );

        $this->source('part-1-operator-guide/nested/README.md', "# Nested\n[Public ID](../../../TERMS.md#renamed-term)");
        try {
            $this->builder()->build(publicPages: $terms);
            self::fail('A link to a heading the published file does not have must fail the build.');
        } catch (\RuntimeException $error) {
            self::assertSame('Missing fragment in docs-book/part-1-operator-guide/nested/README.md: ../../../TERMS.md#renamed-term', $error->getMessage());
        }
    }

    public function testMissingRepositoryTargetFromARootFileNamesTheFile(): void
    {
        file_put_contents($this->root . '/NOTES.md', "# Notes\n[Gone](packages/gone/LICENSE)");
        $this->referenceTemplate();
        $this->expectExceptionMessage('Missing or escaping repository target in NOTES.md: packages/gone/LICENSE');
        $this->builder()->build(publicPages: [self::reference('/notes/', 'NOTES.md')]);
    }

    public function testReferencePageReceivesItsHeadingApartFromTheBodyAndItsHeadings(): void
    {
        file_put_contents($this->root . '/NOTES.md', "# Notes\nThe body.\n## First\n### Term");
        file_put_contents(
            $this->root . '/ui/site/templates/reference.php',
            '<?php echo json_encode(["heading" => $page["heading"], "html" => $page["html"], "headings" => $page["headings"], "source" => $page["source"]], JSON_THROW_ON_ERROR | JSON_HEX_TAG);',
        );
        $this->builder()->build(publicPages: [self::reference('/notes/', 'NOTES.md')]);
        $page = json_decode((string) file_get_contents($this->root . '/var/website/notes/index.html'), true, flags: JSON_THROW_ON_ERROR);

        self::assertStringStartsWith('<h1 id="notes">Notes', $page['heading']);
        self::assertStringStartsWith('<p>The body.</p>', $page['html']);
        self::assertStringNotContainsString('<h1', $page['html']);
        self::assertSame(['notes', 'first', 'term'], array_column($page['headings'], 'id'));
        self::assertSame('NOTES.md', $page['source']);
    }

    /** @return iterable<string, array{?string, string}> */
    public static function unusableReferenceSources(): iterable
    {
        yield 'missing' => [null, 'Missing or escaping reference source for /notes/: NOTES.md'];
        yield 'empty' => [" \n", 'NOTES.md: the reference source is empty'];
        yield 'text before the title' => ["Intro\n# Notes", 'NOTES.md: a reference source opens with its title, its only level-1 heading'];
        yield 'no title' => ['## Notes', 'NOTES.md: a reference source opens with its title, its only level-1 heading'];
        yield 'second title' => ["# Notes\n# More", 'NOTES.md: a reference source opens with its title, its only level-1 heading'];
    }

    #[DataProvider('unusableReferenceSources')]
    public function testReferenceSourceMustExistHaveContentAndOpenWithItsOnlyTitle(?string $content, string $problem): void
    {
        if ($content !== null) {
            file_put_contents($this->root . '/NOTES.md', $content);
        }
        $this->referenceTemplate();
        $this->expectExceptionMessage($problem);
        $this->builder()->build(publicPages: [self::reference('/notes/', 'NOTES.md')]);
    }

    /** @return array{template:string,url:string,title:string,description:string,markdown:string,data:array<string,mixed>} */
    private static function reference(string $url, string $markdown): array
    {
        return ['template' => 'reference', 'url' => $url, 'title' => 'Reference', 'description' => 'A reference page', 'markdown' => $markdown, 'data' => []];
    }

    private function referenceTemplate(): void
    {
        file_put_contents($this->root . '/ui/site/templates/reference.php', '<?php echo $page["heading"], $page["html"];');
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
