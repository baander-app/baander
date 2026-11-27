<?php

declare(strict_types=1);

namespace Baander\DevWebsite\Tests;

use Baander\DevWebsite\Content\DesignSystemPages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DesignSystemPagesTest extends TestCase
{
    private const array SLUGS = ['overview', 'color', 'accents', 'type', 'layout', 'motion', 'iconography', 'writing', 'logo', 'website'];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-design-system-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/ui/site/design-system', 0700, true);
        mkdir($this->root . '/ui/site/templates/design-system', 0700, true);
        foreach (DesignSystemPages::DOCUMENTS as $document) {
            copy(dirname(__DIR__, 3) . '/' . $document, $this->root . '/' . $document);
        }
        foreach (self::SLUGS as $slug) {
            touch($this->root . '/ui/site/templates/design-system/' . $slug . '.php');
        }
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testTheRepositoryIndexYieldsTheOverviewThenNinePagesInOrder(): void
    {
        $pages = DesignSystemPages::load($this->repository());

        self::assertSame(
            ['/design-system/', '/design-system/color/', '/design-system/accents/', '/design-system/type/', '/design-system/layout/', '/design-system/motion/', '/design-system/iconography/', '/design-system/writing/', '/design-system/logo/', '/design-system/website/'],
            array_column($pages, 'url'),
        );
        self::assertSame(array_fill(0, 10, 'design-system'), array_column($pages, 'template'));
        self::assertSame(array_fill(0, 10, 'design-system.css'), array_column($pages, 'stylesheet'));
        self::assertSame([], $pages[0]['trail']);
        self::assertSame([['name' => 'Design system', 'url' => '/design-system/']], $pages[1]['trail']);
        self::assertSame('Color', $pages[1]['title']);
    }

    public function testEveryPageCarriesTheSectionNavigationMarkedForItselfAndTheTypographyPage(): void
    {
        $pages = DesignSystemPages::load($this->repository());

        foreach ($pages as $page) {
            $section = $page['data']['designSystem'];
            self::assertSame('Overview', $section['navigation'][0]['title']);
            $current = array_filter($section['navigation'], static fn(array $link): bool => $link['current']);
            self::assertSame([$page['url']], array_column($current, 'url'));
            self::assertSame([['title' => 'Typography', 'url' => '/typography/']], $section['related']);
        }
        self::assertSame(
            ['app-shell', 'surfaces', 'border-radius', 'spacing', 'footer-player', 'notification-pane'],
            array_keys($pages[4]['data']['designSystem']['borrowed']),
        );
        self::assertSame('Border radius', $pages[4]['data']['designSystem']['borrowed']['border-radius']['title']);
        self::assertSame('ui/DESIGN.md › Layout › Border radius', $pages[4]['data']['designSystem']['borrowed']['border-radius']['source']);
    }

    public function testEachPageNamesTheInputsItReadsAsItsSources(): void
    {
        $sources = array_column(DesignSystemPages::load($this->repository()), 'sources', 'url');

        foreach ($sources as $url => $paths) {
            self::assertContains(DesignSystemPages::INDEX, $paths, $url);
            self::assertContains('ui/site/templates/design-system.php', $paths, $url);
            self::assertContains('ui/site/assets/design-system.css', $paths, $url);
        }
        self::assertContains('ui/site/templates/design-system/color.php', $sources['/design-system/color/']);
        self::assertContains('ui/design-tokens/build/web/theme.css', $sources['/design-system/color/']);
        self::assertContains('ui/site/theme-tone.css', $sources['/design-system/color/']);
        self::assertContains('ui/ICON-GUIDE.md', $sources['/design-system/iconography/']);
        self::assertContains('ui/web/src', $sources['/design-system/motion/']);
        self::assertContains('ui/web/public/favicon.svg', $sources['/design-system/logo/']);
        self::assertNotContains('ui/ICON-GUIDE.md', $sources['/design-system/color/']);
    }

    public function testThePagesCarryTheValuesTheirBodiesShow(): void
    {
        $values = array_map(static fn(array $page): array => $page['data']['designSystem']['values'], DesignSystemPages::load($this->repository()));

        self::assertSame(['Color', '/design-system/color/'], [$values[0]['pages'][0]['title'], $values[0]['pages'][0]['url']]);
        self::assertCount(9, $values[0]['pages']);
        $foreground = array_find($values[1]['roles'], static fn(array $role): bool => $role['variables'][0]['name'] === '--color-foreground');
        self::assertSame(['dark' => '#f0f0f2', 'warm' => '#3d3225', 'cool' => '#2d3748', 'balanced' => '#2d2d2d'], $foreground['variables'][0]['values']);
        self::assertSame(['#f0f0f2', '#e6e6e9'], [$values[1]['appForeground'], $values[1]['siteForeground']]);
        self::assertCount(8, $values[2]['derivations']['cool']);
        self::assertSame('Continue watching', $values[3]['roles']['hero']['sample']);
        self::assertSame('--space-xs', $values[4]['spacing'][0]['name']);
        self::assertSame('Hover state', $values[5]['rows'][7]['transition']);
        $fast = array_find($values[5]['durations'], static fn(array $duration): bool => $duration['name'] === '--duration-fast');
        self::assertContains('ui/site/assets/site.css', $fast['website']);
        $hover = array_find($values[5]['durations'], static fn(array $duration): bool => $duration['name'] === '--duration-hover');
        self::assertContains('ui/web/src/shared/components/ui/button.tsx', $hover['application']);
        self::assertSame([[256], [128, 64], [32, 16]], array_column($values[8]['marks'], 'sizes'));
        self::assertSame('/assets/site/icon.svg', $values[8]['marks'][1]['url']);
        self::assertSame('400 16px/1.65 var(--font-ui)', $values[9]['departures'][0]['website']);
        self::assertSame('balanced', $values[9]['lightMood']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function brokenIndexes(): iterable
    {
        yield 'unknown key' => [
            self::page('color', "'subtitle' => 'Swatches',"),
            'ui/site/design-system/index.php: pages[0].subtitle: unknown key',
        ];
        yield 'missing body template' => [
            self::page('colour'),
            'ui/site/design-system/index.php: pages[0].slug: has no body template ui/site/templates/design-system/colour.php',
        ];
        yield 'missing borrowed heading' => [
            self::page('color', "'borrow' => [['id' => 'radius', 'document' => 'ui/DESIGN.md', 'headings' => ['Layout', 'Corner radius']]],"),
            'ui/site/design-system/index.php: pages[0].borrow[0]: ui/DESIGN.md › Layout › Corner radius does not exist.',
        ];
        yield 'document outside the design documents' => [
            self::page('color', "'borrow' => [['id' => 'agents', 'document' => 'AGENTS.md', 'headings' => ['Workflow']]],"),
            'ui/site/design-system/index.php: pages[0].borrow[0].document: must be one of ui/DESIGN.md, ui/ICON-GUIDE.md',
        ];
        yield 'tables that is not a flag' => [
            self::page('color', "'borrow' => [['id' => 'spacing', 'document' => 'ui/DESIGN.md', 'headings' => ['Layout', 'Spacing'], 'tables' => 'no']],"),
            'ui/site/design-system/index.php: pages[0].borrow[0].tables: must be true or false',
        ];
        yield 'long title' => [
            self::page('color', title: 'Semantic color roles for every mood and every surface'),
            'makes the title "Semantic color roles for every mood and every surface — Design system — Bånder", which has 78 characters; a title has at most 60',
        ];
        yield 'long description' => [
            self::page('color', description: str_repeat('a', 156)),
            'ui/site/design-system/index.php: pages[0].description: has 156 characters; a search description has at most 155',
        ];
        yield 'repeated description' => [
            self::page('color', description: 'The overview.'),
            'ui/site/design-system/index.php: pages[0].description: repeats the description of overview',
        ];
        yield 'repeated slug' => [
            self::page('color') . self::page('color', description: 'Another page.'),
            'ui/site/design-system/index.php: pages[1].slug: repeats the slug of pages.0',
        ];
    }

    #[DataProvider('brokenIndexes')]
    public function testABrokenIndexFailsNamingTheFileAndThePlace(string $pages, string $message): void
    {
        file_put_contents($this->root . '/' . DesignSystemPages::INDEX, <<<PHP
            <?php

            return [
                'overview' => ['title' => 'Design system', 'description' => 'The overview.', 'opening' => 'All of it.'],
                'pages' => [{$pages}],
            ];
            PHP);

        $this->expectExceptionMessage($message);
        DesignSystemPages::load($this->root);
    }

    private function repository(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function page(string $slug, string $extra = '', string $title = 'Color', string $description = 'Colors.'): string
    {
        return "['slug' => '{$slug}', 'title' => '{$title}', 'summary' => 'Roles.', 'description' => '{$description}', 'opening' => 'Roles and values.', {$extra}],";
    }
}
