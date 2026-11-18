<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use RuntimeException;

/**
 * The site's top menu, which the header and the footer both render. Product holds the features
 * overview, every feature page under its audience group as the features overview orders them, and
 * the product entries of `ui/site/menu.php`; Resources holds that file's labelled columns. Each
 * feature entry is described by its page's summary and every other entry by the menu file.
 *
 * The menu marks the page it is shown on: the entry for that page is the current page, an entry for
 * a section the page belongs to (the features, the operator manual or the developer guide) is
 * marked as its section, and a panel holding a marked entry is marked too.
 *
 * @phpstan-import-type PublicPage from WebsiteBuilder
 * @phpstan-type MenuEntry array{title: string, url: string, description: string}
 * @phpstan-type MenuColumn array{title: ?string, groups: list<array{title: ?string, entries: list<MenuEntry>}>}
 * @phpstan-type MarkedEntry array{title: string, url: string, description: string, current: ?string}
 * @phpstan-type MarkedPanel array{title: string, current: bool, columns: list<array{title: ?string, groups: list<array{title: ?string, entries: list<MarkedEntry>}>}>}
 */
final readonly class SiteMenu
{
    public const string PATH = 'ui/site/menu.php';
    private const array ENTRY = ['title' => FeatureField::Text, 'url' => FeatureField::Text, 'description' => FeatureField::Text];
    private const array SHAPE = [
        'features' => FeatureField::Text,
        'product' => [self::ENTRY],
        'resources' => [['title' => FeatureField::Text, 'entries' => [self::ENTRY]]],
    ];
    /** Entries whose pages head a section: the entry is marked on every page below it. */
    private const array SECTIONS = ['/features/', '/docs/operator-guide/', '/docs/developer-guide/'];

    /** @param array<string, list<MenuColumn>> $panels each panel's columns by its title */
    private function __construct(private array $panels) {}

    /** @param list<PublicPage> $featurePages the features overview and its pages, as FeaturePages loads them */
    public static function load(string $root, array $featurePages): self
    {
        $file = FeatureFile::read(rtrim($root, '/'), self::PATH, self::SHAPE);
        if ($file->errors !== []) {
            throw new RuntimeException(implode("\n", $file->errors));
        }
        $overview = array_find($featurePages, static fn(array $page): bool => $page['template'] === 'features')
            ?? throw new RuntimeException(self::PATH . ': the menu lists the feature pages, but there is no features overview');
        /** @var list<array{title: string, pages: list<array{name: string, summary: string, url: string}>}> $audiences */
        $audiences = $overview['data']['features']['groups'];
        /** @var array{features: string, product: list<MenuEntry>, resources: list<array{title: string, entries: list<MenuEntry>}>} $menu */
        $menu = $file->data;
        $features = ['title' => $overview['title'], 'url' => $overview['url'], 'description' => $menu['features']];

        return new self([
            'Product' => [
                ['title' => null, 'groups' => [
                    ['title' => null, 'entries' => [$features]],
                    ...array_map(static fn(array $audience): array => [
                        'title' => $audience['title'],
                        'entries' => array_map(
                            static fn(array $page): array => ['title' => $page['name'], 'url' => $page['url'], 'description' => $page['summary']],
                            $audience['pages'],
                        ),
                    ], $audiences),
                ]],
                ['title' => null, 'groups' => [['title' => null, 'entries' => $menu['product']]]],
            ],
            'Resources' => array_map(
                static fn(array $column): array => ['title' => $column['title'], 'groups' => [['title' => null, 'entries' => $column['entries']]]],
                $menu['resources'],
            ),
        ]);
    }

    /** @return list<MarkedPanel> the panels with the entries for the page at this URL marked */
    public function forPage(string $url): array
    {
        $mark = static fn(array $entry): array => [...$entry, 'current' => match (true) {
            $entry['url'] === $url => 'page',
            in_array($entry['url'], self::SECTIONS, true) && str_starts_with($url, $entry['url']) => 'true',
            default => null,
        }];
        $panels = [];
        foreach ($this->panels as $title => $columns) {
            $columns = array_map(static fn(array $column): array => [
                ...$column,
                'groups' => array_map(
                    static fn(array $group): array => [...$group, 'entries' => array_map($mark, $group['entries'])],
                    $column['groups'],
                ),
            ], $columns);
            $marked = array_any(
                $columns,
                static fn(array $column): bool => array_any(
                    $column['groups'],
                    static fn(array $group): bool => array_any($group['entries'], static fn(array $entry): bool => $entry['current'] !== null),
                ),
            );
            $panels[] = ['title' => $title, 'current' => $marked, 'columns' => $columns];
        }

        return $panels;
    }
}
