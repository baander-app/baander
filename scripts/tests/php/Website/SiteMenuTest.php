<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests\Website;

use Baander\Tooling\Website\FeaturePages;
use Baander\Tooling\Website\SiteMenu;
use Baander\Tooling\Website\WebsiteBuilder;
use PHPUnit\Framework\TestCase;

final class SiteMenuTest extends TestCase
{
    /** A features overview as the feature pages describe it: one audience group with two pages. */
    private const array OVERVIEW = [
        'template' => 'features',
        'url' => '/features/',
        'title' => 'Features',
        'description' => 'A tour of the features.',
        'data' => ['features' => ['groups' => [[
            'id' => 'listeners',
            'title' => 'For listening',
            'lead' => 'Play your music.',
            'pages' => [
                ['name' => 'Music library', 'summary' => 'Browse the collection.', 'url' => '/features/library/'],
                ['name' => 'Player', 'summary' => 'Play songs from a queue.', 'url' => '/features/player/'],
            ],
        ]]]],
    ];
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-site-menu-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/ui/site/templates', 0700, true);
        mkdir($this->root . '/docs-book/part-1-operator-guide', 0700, true);
        mkdir($this->root . '/docs-book/part-2-developer-guide', 0700, true);
        file_put_contents($this->root . '/docs-book/part-1-operator-guide/README.md', '# Operator');
        file_put_contents($this->root . '/docs-book/part-2-developer-guide/README.md', '# Developer');
        $this->menuFile(<<<'PHP'
            'features' => 'Every feature page, by audience.',
            'product' => [['title' => 'Audio Core', 'url' => '/bac/', 'description' => 'The codec library.']],
            'resources' => [
                ['title' => 'Run it', 'entries' => [['title' => 'Operator manual', 'url' => '/docs/operator-guide/', 'description' => 'Run a server.']]],
                ['title' => 'Build on it', 'entries' => [['title' => 'Developer guide', 'url' => '/docs/developer-guide/', 'description' => 'Change the code.']]],
            ],
            PHP);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testTheSiteMenuGroupsProductAndResourcesAndDescribesEveryEntry(): void
    {
        $root = dirname(__DIR__, 4);
        [$product, $resources] = SiteMenu::load($root, FeaturePages::load($root))->forPage('/');

        self::assertSame('Product', $product['title']);
        [$features, $more] = $product['columns'];
        self::assertSame(
            [null, 'For listening', 'For running it', 'For building on it'],
            array_column($features['groups'], 'title'),
        );
        self::assertSame([['Features', '/features/']], self::titlesAndUrls($features['groups'][0]['entries']));
        self::assertSame(
            ['/features/library/', '/features/player/', '/features/equalizer/', '/features/playlists/', '/features/destinations/'],
            array_column($features['groups'][1]['entries'], 'url'),
        );
        self::assertSame([['Audio Core', '/bac/'], ['Strategy', '/strategy/']], self::titlesAndUrls($more['groups'][0]['entries']));

        self::assertSame('Resources', $resources['title']);
        self::assertSame(['Run it', 'Build on it', 'Open source'], array_column($resources['columns'], 'title'));
        self::assertSame(
            [
                [['Operator manual', '/docs/operator-guide/'], ['Deploy preset', '/deploy/']],
                [['Developer guide', '/docs/developer-guide/'], ['Concepts', '/concepts/'], ['Typography', '/typography/']],
                [['Third-party licensing', '/licensing/']],
            ],
            array_map(static fn(array $column): array => self::titlesAndUrls($column['groups'][0]['entries']), $resources['columns']),
        );

        foreach ([$product, $resources] as $panel) {
            foreach (self::entries($panel) as $entry) {
                self::assertNotSame('', trim($entry['description']), $entry['title'] . ' has no description');
            }
        }
    }

    public function testThePageItselfIsTheCurrentEntryAndItsSectionAndPanelAreMarked(): void
    {
        $menu = SiteMenu::load($this->root, [self::OVERVIEW]);

        [$product, $resources] = $menu->forPage('/docs/developer-guide/testing/');
        self::assertFalse($product['current']);
        self::assertTrue($resources['current']);
        self::assertSame(['Operator manual' => null, 'Developer guide' => 'true'], self::marks($resources));

        [$product, $resources] = $menu->forPage('/features/player/');
        self::assertTrue($product['current']);
        self::assertFalse($resources['current']);
        self::assertSame(['Features' => 'true', 'Music library' => null, 'Player' => 'page', 'Audio Core' => null], self::marks($product));

        [$product] = $menu->forPage('/features/');
        self::assertSame(['Features' => 'page', 'Music library' => null, 'Player' => null, 'Audio Core' => null], self::marks($product));

        [$product, $resources] = $menu->forPage('/bac/');
        self::assertSame('page', self::marks($product)['Audio Core']);
        self::assertSame(['Operator manual' => null, 'Developer guide' => null], self::marks($resources));

        [$product, $resources] = $menu->forPage('/');
        self::assertFalse($product['current'] || $resources['current']);
    }

    public function testAMenuFileWithoutADescriptionFailsTheBuildNamingTheEntry(): void
    {
        $this->menuFile(<<<'PHP'
            'features' => 'Every feature page, by audience.',
            'product' => [['title' => 'Audio Core', 'url' => '/bac/']],
            'resources' => [['title' => 'Run it', 'entries' => [['title' => 'Operator manual', 'url' => '/docs/operator-guide/', 'description' => '']]]],
            PHP);

        $this->expectExceptionMessage(
            "ui/site/menu.php: product[0].description: missing\nui/site/menu.php: resources[0].entries[0].description: must be a non-empty string",
        );
        SiteMenu::load($this->root, [self::OVERVIEW]);
    }

    public function testEveryTemplateReceivesTheMenuMarkedForItsOwnPage(): void
    {
        foreach (['page', 'home', '404', 'feature'] as $template) {
            file_put_contents(
                $this->root . '/ui/site/templates/' . $template . '.php',
                '<?php echo json_encode(array_map(static fn(array $panel): bool => $panel["current"], $site["menu"]), JSON_THROW_ON_ERROR);',
            );
        }
        new WebsiteBuilder($this->root, 'https://github.com/baander-app/baander/tree/abc123', SiteMenu::load($this->root, [self::OVERVIEW]))
            ->build(publicPages: [[
                'template' => 'feature', 'url' => '/features/player/', 'title' => 'Player',
                'description' => 'Play songs from a queue.', 'data' => [],
            ]]);

        self::assertSame('[false,true]', file_get_contents($this->root . '/var/website/docs/developer-guide/index.html'));
        self::assertSame('[true,false]', file_get_contents($this->root . '/var/website/features/player/index.html'));
        self::assertSame('[false,false]', file_get_contents($this->root . '/var/website/index.html'));
        self::assertSame('[false,false]', file_get_contents($this->root . '/var/website/404.html'));
    }

    private function menuFile(string $body): void
    {
        file_put_contents($this->root . '/ui/site/menu.php', "<?php\n\nreturn [\n" . $body . "\n];\n");
    }

    /**
     * @param list<array{title: string, url: string}> $entries
     * @return list<array{string, string}>
     */
    private static function titlesAndUrls(array $entries): array
    {
        return array_map(static fn(array $entry): array => [$entry['title'], $entry['url']], $entries);
    }

    /**
     * @param array{columns: list<array{groups: list<array{entries: list<array{title: string, description: string, current: ?string}>}>}>} $panel
     * @return list<array{title: string, description: string, current: ?string}>
     */
    private static function entries(array $panel): array
    {
        return array_merge(...array_map(
            static fn(array $column): array => array_merge(...array_column($column['groups'], 'entries')),
            $panel['columns'],
        ));
    }

    /**
     * @param array{columns: list<array{groups: list<array{entries: list<array{title: string, description: string, current: ?string}>}>}>} $panel
     * @return array<string, ?string> each entry's current-page mark by its title
     */
    private static function marks(array $panel): array
    {
        $entries = self::entries($panel);

        return array_combine(array_column($entries, 'title'), array_column($entries, 'current'));
    }
}
