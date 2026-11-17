<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use RuntimeException;

/**
 * The site's feature pages. `ui/site/features/index.php` lists the pages in order, grouped by
 * audience, and each `ui/site/features/<slug>.php` holds one page. Loading checks everything the
 * files and the pictures alone can show to be wrong and reports all of it at once, each problem
 * under the file that has it. What depends on the manual travels with each page as references,
 * which the builder checks once it has read the manual.
 *
 * @phpstan-import-type PublicPage from WebsiteBuilder
 * @phpstan-import-type ManualBlockReference from WebsiteBuilder
 */
final class FeaturePages
{
    public const string DIRECTORY = 'ui/site/features';
    private const string INDEX = self::DIRECTORY . '/index.php';
    private const string OVERVIEW_URL = '/features/';
    /** The overview's title, and the section every feature page's title and breadcrumb name. */
    private const string SECTION = 'Features';
    private const string STYLESHEET = 'features.css';
    private const string TEMPLATES = 'ui/site/templates/';

    private const array MANUAL_LINK = ['label' => FeatureField::Text, 'href' => FeatureField::ManualUrl];
    private const array NOTE = ['title' => FeatureField::Text, 'text' => FeatureField::Html];
    private const array INDEX_SHAPE = [
        'description' => FeatureField::Description,
        'opening' => ['headline' => FeatureField::Html, 'lead' => [FeatureField::Html]],
        'groups' => [[
            'id' => FeatureField::Id,
            'title' => FeatureField::Text,
            'lead' => FeatureField::Html,
            'pages' => [FeatureField::Id],
        ]],
        'more?' => [['name' => FeatureField::Text, 'text' => FeatureField::Html, 'manual' => self::MANUAL_LINK]],
    ];
    private const array PAGE_SHAPE = [
        'name' => FeatureField::Text,
        'summary' => FeatureField::Text,
        'description' => FeatureField::Description,
        'headline' => FeatureField::Html,
        'lead' => [FeatureField::Html],
        'hero' => FeatureField::Figure,
        'tour' => [
            [
                'id' => FeatureField::Id,
                'heading' => FeatureField::Html,
                'text' => [FeatureField::Html],
                'figure?' => FeatureField::Figure,
                'layout?' => FeatureField::Layout,
            ],
            2,
            4,
        ],
        'guide' => ['needs' => FeatureField::Html, 'steps' => [self::NOTE]],
        'planned?' => [self::NOTE],
        'limits' => [FeatureField::Html],
        'manual?' => [self::MANUAL_LINK],
    ];

    /**
     * @return list<PublicPage> the overview, then each page in the index's order; none while the directory holds no data file
     */
    public static function load(string $root): array
    {
        $root = rtrim($root, '/');
        $paths = array_map(
            static fn(string $path): string => self::DIRECTORY . '/' . basename($path),
            glob($root . '/' . self::DIRECTORY . '/*.php') ?: [],
        );
        if ($paths === []) {
            return [];
        }
        $index = FeatureFile::read($root, self::INDEX, self::INDEX_SHAPE);
        $pages = [];
        foreach (array_diff($paths, [self::INDEX]) as $path) {
            $pages[$path] = FeatureFile::read($root, $path, self::PAGE_SHAPE);
        }
        $order = self::order($index);
        self::checkOrder($index, $pages, $order);
        self::checkTitles($pages);
        self::checkRepeats([$index, ...array_values($pages)], 'description', '');
        self::checkRepeats(array_values($pages), 'name', ', so both pages would have one title');
        $errors = [$index, ...array_values($pages)]
            |> (static fn(array $files): array => array_map(static fn(FeatureFile $file): array => $file->errors, $files))
            |> (static fn(array $errors): array => array_merge(...$errors))
            |> array_unique(...);
        if ($errors !== []) {
            throw new RuntimeException(implode("\n", $errors));
        }

        return self::descriptors($index, $pages, $order);
    }

    private static function path(string $slug): string
    {
        return self::DIRECTORY . '/' . $slug . '.php';
    }

    /** @return list<string> the slugs the index lists, in its order across the groups */
    private static function order(FeatureFile $index): array
    {
        $slugs = [];
        foreach (is_array($index->data['groups'] ?? null) ? $index->data['groups'] : [] as $group) {
            foreach (is_array($group) && is_array($group['pages'] ?? null) ? $group['pages'] : [] as $slug) {
                if (is_string($slug)) {
                    $slugs[] = $slug;
                }
            }
        }

        return $slugs;
    }

    /**
     * The index lists every data file once, and every slug it lists has a data file.
     *
     * @param array<string, FeatureFile> $pages
     * @param list<string> $order
     */
    private static function checkOrder(FeatureFile $index, array $pages, array $order): void
    {
        foreach (array_count_values($order) as $slug => $times) {
            if ($times > 1) {
                $index->fail([], 'lists "' . $slug . '" ' . $times . ' times');
            }
            if (!isset($pages[self::path((string) $slug)])) {
                $index->fail([], 'lists "' . $slug . '", which has no data file ' . self::path((string) $slug));
            }
        }
        $listed = array_map(self::path(...), $order);
        foreach ($pages as $path => $page) {
            if (!in_array($path, $listed, true)) {
                $page->fail([], 'not listed in ' . self::INDEX);
            }
        }
    }

    /**
     * A page's title is its name, the section and the site's name, and must fit in full.
     *
     * @param array<string, FeatureFile> $pages
     */
    private static function checkTitles(array $pages): void
    {
        foreach ($pages as $page) {
            $title = is_string($page->data['name'] ?? null) ? SearchMetadata::fullTitle($page->data['name'], self::SECTION) : '';
            if (mb_strlen($title) > SearchMetadata::TITLE_LIMIT) {
                $page->fail(['name'], 'makes the title "' . $title . '", which has ' . mb_strlen($title) . ' characters; a title has at most ' . SearchMetadata::TITLE_LIMIT);
            }
        }
    }

    /**
     * @param list<FeatureFile> $files
     * @param string $consequence what the repetition leads to, appended to the problem
     */
    private static function checkRepeats(array $files, string $key, string $consequence): void
    {
        $first = [];
        foreach ($files as $file) {
            $value = $file->data[$key] ?? null;
            if (!is_string($value)) {
                continue;
            }
            if (isset($first[$value])) {
                $file->fail([$key], 'repeats the ' . $key . ' of ' . $first[$value] . $consequence);
            }
            $first[$value] ??= $file->path;
        }
    }

    /**
     * @param array<string, FeatureFile> $pages
     * @param list<string> $order
     * @return list<PublicPage>
     */
    private static function descriptors(FeatureFile $index, array $pages, array $order): array
    {
        $cards = array_combine($order, array_map(static fn(string $slug): array => [
            'name' => $pages[self::path($slug)]->data['name'],
            'summary' => $pages[self::path($slug)]->data['summary'],
            'url' => self::OVERVIEW_URL . $slug . '/',
        ], $order));
        $groups = [];
        foreach ($index->data['groups'] as $group) {
            $groups += array_fill_keys($group['pages'], ['id' => $group['id'], 'title' => $group['title']]);
        }
        $neighbour = static fn(?string $slug): ?array => $slug === null
            ? null
            : ['name' => $cards[$slug]['name'], 'url' => $cards[$slug]['url']];

        return [
            self::overview($index, $cards),
            ...array_map(static fn(string $slug, int $position): array => self::page($pages[self::path($slug)], $cards[$slug], [
                'group' => $groups[$slug],
                'previous' => $neighbour($order[$position - 1] ?? null),
                'next' => $neighbour($order[$position + 1] ?? null),
                'overview' => self::OVERVIEW_URL,
            ]), $order, array_keys($order)),
        ];
    }

    /**
     * @param array<string, array{name: string, summary: string, url: string}> $cards each page's name, summary and URL, by slug
     * @return PublicPage
     */
    private static function overview(FeatureFile $index, array $cards): array
    {
        return [
            'template' => 'features',
            'url' => self::OVERVIEW_URL,
            'title' => self::SECTION,
            'description' => $index->data['description'],
            'stylesheet' => self::STYLESHEET,
            'sources' => [self::DIRECTORY, self::TEMPLATES . 'features.php'],
            'references' => self::references($index, 'features'),
            'data' => ['features' => [
                ...$index->data,
                'groups' => array_map(static fn(array $group): array => [
                    ...$group,
                    'pages' => array_map(static fn(string $slug): array => $cards[$slug], $group['pages']),
                ], $index->data['groups']),
                'more' => $index->data['more'] ?? [],
            ]],
        ];
    }

    /**
     * @param array{name: string, summary: string, url: string} $card
     * @param array<string, mixed> $navigation the page's group, its neighbours in the index's order and the overview
     * @return PublicPage
     */
    private static function page(FeatureFile $page, array $card, array $navigation): array
    {
        return [
            'template' => 'feature',
            'url' => $card['url'],
            'title' => $card['name'],
            'description' => $page->data['description'],
            'stylesheet' => self::STYLESHEET,
            'trail' => [['name' => self::SECTION, 'url' => self::OVERVIEW_URL]],
            'sources' => self::sources($page),
            'references' => self::references($page, 'feature'),
            'data' => ['feature' => self::feature($page), ...$navigation],
        ];
    }

    /**
     * The page as its template renders it: every optional part present, each picture with the
     * dimensions of its files.
     *
     * @return array<string, mixed>
     */
    private static function feature(FeatureFile $page): array
    {
        $sized = static fn(?array $figure): ?array => isset($figure['picture']) ? $figure + $page->pictures[$figure['picture']] : $figure;

        return [
            ...$page->data,
            'hero' => $sized($page->data['hero']),
            'tour' => array_map(
                static fn(array $section): array => ['layout' => 'side', ...$section, 'figure' => $sized($section['figure'] ?? null)],
                $page->data['tour'],
            ),
            'planned' => $page->data['planned'] ?? [],
            'manual' => $page->data['manual'] ?? [],
        ];
    }

    /**
     * The files whose newest commit dates a page: its data file, the feature templates, its
     * pictures and the manual pages it borrows blocks from.
     *
     * @return list<string>
     */
    private static function sources(FeatureFile $page): array
    {
        $pictures = array_map(static fn(string $name): array => array_values(Screenshots::files($name)), array_keys($page->pictures));
        $manual = array_map(static fn(array $block): string => 'docs-book/' . $block['manual'], $page->blocks);

        return [
            $page->path,
            self::TEMPLATES . 'feature.php',
            self::TEMPLATES . 'features.php',
            self::TEMPLATES . 'feature-figure.php',
            ...array_merge(...$pictures),
            ...array_unique($manual),
        ];
    }

    /**
     * @param string $key the name the template data keeps the file's data under
     * @return array{file: string, links: list<string>, blocks: list<ManualBlockReference>}
     */
    private static function references(FeatureFile $file, string $key): array
    {
        return [
            'file' => $file->path,
            'links' => array_values(array_unique($file->links)),
            'blocks' => array_map(static fn(array $block): array => [...$block, 'into' => [$key, ...$block['into']]], $file->blocks),
        ];
    }
}
