<?php

declare(strict_types=1);

namespace Baander\DevWebsite\Content;

use Baander\DevWebsite\Assets\AssetPublication;
use Baander\DevWebsite\Metadata\SearchMetadata;
use Baander\DevWebsite\WebsiteBuilder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * The design system section of the site: an overview at `/design-system/` and one page per part of
 * the design language below it. `ui/site/design-system/index.php` lists the pages in order with their
 * titles, summaries, search descriptions and openings, and names the sections each page borrows from
 * `ui/DESIGN.md` or `ui/ICON-GUIDE.md`. One template, `design-system.php`, lays every page out and
 * includes the page's body from `templates/design-system/<slug>.php`.
 *
 * Every value a page shows is read when the site builds: colors, scales and durations from the
 * generated web theme, type roles and accents from the token inputs, the website's own departures
 * from its stylesheets, and the marks from `ui/web/public/`. A page names all of those inputs as its
 * sources, so its sitemap date moves when any of them changes.
 *
 * @phpstan-import-type PublicPage from WebsiteBuilder
 */
final class DesignSystemPages
{
    public const string DIRECTORY = 'ui/site/design-system';
    public const string INDEX = self::DIRECTORY . '/index.php';
    /** The documents a page may borrow sections from. */
    public const array DOCUMENTS = ['ui/DESIGN.md', 'ui/ICON-GUIDE.md'];
    /** A sample text for each type role, shared by the type page and the typography page. */
    public const array TYPE_SAMPLES = [
        'hero' => 'Continue watching',
        'title-lg' => 'Albums',
        'title' => 'Recently added',
        'subtitle' => 'Season 2 · 8 episodes',
        'body' => 'Scan your music folders to add new albums to the library.',
        'body-sm' => '12 songs, 48 minutes',
        'caption' => 'Added 3 days ago',
        'label' => 'New release',
        'timecode' => '1:42:07',
        'mono-sm' => 'FLAC · 24-bit · 96 kHz',
    ];
    /** Paths whose history dates the section's pages, beyond the documents and token files the pages name. */
    public const array SOURCES = [
        self::DIRECTORY,
        self::TEMPLATE,
        self::BODIES,
        self::STYLESHEET_SOURCE,
        self::SITE_STYLESHEET,
        self::TONE,
        self::MARKS,
        self::APPLICATION,
        'ui/site/assets',
    ];

    private const string URL = '/design-system/';
    private const string SECTION = 'Design system';
    private const string TEMPLATE = 'ui/site/templates/design-system.php';
    private const string BODIES = 'ui/site/templates/design-system';
    private const string STYLESHEET = 'design-system.css';
    private const string STYLESHEET_SOURCE = 'ui/site/assets/' . self::STYLESHEET;
    private const string SITE_STYLESHEET = 'ui/site/assets/site.css';
    private const string TONE = 'ui/site/theme-tone.css';
    private const string MARKS = 'ui/web/public';
    private const string APPLICATION = 'ui/web/src';
    /** The dark theme's selector in the website's tone, whose foreground it softens. */
    private const string DARK_TONE = ':root[data-theme="dark"]:not([data-accent])';
    private const string OVERVIEW = 'overview';

    private const array BORROW = [
        'id' => FeatureField::Id,
        'document' => FeatureField::Text,
        'headings' => [FeatureField::Text],
        'tables?' => FeatureField::Flag,
        'from?' => FeatureField::Text,
    ];
    private const array PAGE = [
        'title' => FeatureField::Text,
        'description' => FeatureField::Description,
        'opening' => FeatureField::Html,
        'borrow?' => [self::BORROW],
    ];
    private const array SHAPE = [
        'overview' => self::PAGE,
        'pages' => [self::PAGE + ['slug' => FeatureField::Id, 'summary' => FeatureField::Text]],
    ];

    /** @return list<PublicPage> the overview, then each page in the index's order */
    public static function load(string $root): array
    {
        $root = rtrim($root, '/');
        $file = FeatureFile::read($root, self::INDEX, self::SHAPE);
        if ($file->errors === []) {
            self::check($root, $file);
        }
        if ($file->errors !== []) {
            throw new RuntimeException(implode("\n", $file->errors));
        }
        /** @var array{overview: array<string, mixed>, pages: list<array<string, mixed>>} $index */
        $index = $file->data;
        $entries = [['slug' => self::OVERVIEW, ...$index['overview']], ...$index['pages']];
        $tokens = DesignTokens::load($root);
        $documents = array_combine(self::DOCUMENTS, array_map(static fn(string $path): DesignDocument => DesignDocument::load($root, $path), self::DOCUMENTS));
        $navigation = array_map(static fn(array $entry): array => ['title' => $entry['slug'] === self::OVERVIEW ? 'Overview' : $entry['title'], 'url' => self::url($entry['slug'])], $entries);
        $pages = [];
        foreach ($entries as $entry) {
            [$values, $inputs] = self::values($root, $entry['slug'], $tokens, $documents, $index['pages']);
            $borrow = $entry['borrow'] ?? [];
            $pages[] = [
                'template' => 'design-system',
                'url' => self::url($entry['slug']),
                'title' => $entry['title'],
                'description' => $entry['description'],
                'stylesheet' => self::STYLESHEET,
                'sources' => [
                    self::INDEX,
                    self::TEMPLATE,
                    self::BODIES . '/' . $entry['slug'] . '.php',
                    self::STYLESHEET_SOURCE,
                    ...array_column($borrow, 'document'),
                    ...$inputs,
                ] |> array_unique(...) |> array_values(...),
                'trail' => $entry['slug'] === self::OVERVIEW ? [] : [['name' => self::SECTION, 'url' => self::URL]],
                'data' => ['designSystem' => [
                    'slug' => $entry['slug'],
                    'title' => $entry['title'],
                    'opening' => $entry['opening'],
                    'navigation' => array_map(static fn(array $link): array => $link + ['current' => $link['url'] === self::url($entry['slug'])], $navigation),
                    'related' => [['title' => 'Typography', 'url' => '/typography/']],
                    'borrowed' => self::borrowed($borrow, $documents),
                    'values' => $values,
                ]],
            ];
        }

        return $pages;
    }

    private static function url(string $slug): string
    {
        return $slug === self::OVERVIEW ? self::URL : self::URL . $slug . '/';
    }

    /** Checks what the shape alone cannot: slugs, titles, repeats, body templates and borrowed sections. */
    private static function check(string $root, FeatureFile $file): void
    {
        $seen = ['slugs' => [self::OVERVIEW => 'overview'], 'descriptions' => []];
        $entries = ['overview' => ['slug' => self::OVERVIEW, ...$file->data['overview']]];
        foreach ($file->data['pages'] as $position => $page) {
            $entries['pages.' . $position] = $page;
        }
        foreach ($entries as $place => $entry) {
            $at = explode('.', $place) |> (static fn(array $parts): array => array_map(static fn(string $part): int|string => is_numeric($part) ? (int) $part : $part, $parts));
            if ($place !== 'overview') {
                if (isset($seen['slugs'][$entry['slug']])) {
                    $file->fail([...$at, 'slug'], 'repeats the slug of ' . $seen['slugs'][$entry['slug']]);
                }
                $seen['slugs'][$entry['slug']] ??= $place;
            }
            if (isset($seen['descriptions'][$entry['description']])) {
                $file->fail([...$at, 'description'], 'repeats the description of ' . $seen['descriptions'][$entry['description']]);
            }
            $seen['descriptions'][$entry['description']] ??= $place;
            $title = $place === 'overview' ? SearchMetadata::fullTitle($entry['title'], null) : SearchMetadata::fullTitle($entry['title'], self::SECTION);
            if (mb_strlen($title) > SearchMetadata::TITLE_LIMIT) {
                $file->fail([...$at, 'title'], 'makes the title "' . $title . '", which has ' . mb_strlen($title) . ' characters; a title has at most ' . SearchMetadata::TITLE_LIMIT);
            }
            if (!is_file($root . '/' . self::BODIES . '/' . $entry['slug'] . '.php')) {
                $file->fail([...$at, 'slug'], 'has no body template ' . self::BODIES . '/' . $entry['slug'] . '.php');
            }
            $ids = [];
            foreach ($entry['borrow'] ?? [] as $position => $borrow) {
                $where = [...$at, 'borrow', $position];
                if (in_array($borrow['id'], $ids, true)) {
                    $file->fail([...$where, 'id'], 'repeats the id ' . $borrow['id']);
                }
                $ids[] = $borrow['id'];
                if (!in_array($borrow['document'], self::DOCUMENTS, true)) {
                    $file->fail([...$where, 'document'], 'must be one of ' . implode(', ', self::DOCUMENTS));
                    continue;
                }
                try {
                    DesignDocument::load($root, $borrow['document'])->section($borrow['headings'], $borrow['tables'] ?? true, $borrow['from'] ?? null);
                } catch (RuntimeException $problem) {
                    $file->fail($where, $problem->getMessage());
                }
            }
        }
    }

    /**
     * @param list<array{id: string, document: string, headings: list<string>, tables?: bool, from?: string}> $borrow
     * @param array<string, DesignDocument> $documents
     * @return array<string, array{title: string, html: string, source: string}>
     */
    private static function borrowed(array $borrow, array $documents): array
    {
        $sections = [];
        foreach ($borrow as $entry) {
            $sections[$entry['id']] = $documents[$entry['document']]->section($entry['headings'], $entry['tables'] ?? true, $entry['from'] ?? null)
                + ['source' => $entry['document'] . ' › ' . implode(' › ', $entry['headings'])];
        }

        return $sections;
    }

    /**
     * The values a page shows, and the repository paths they are read from.
     *
     * @param array<string, DesignDocument> $documents
     * @param list<array<string, mixed>> $pages
     * @return array{array<string, mixed>, list<string>}
     */
    private static function values(string $root, string $slug, DesignTokens $tokens, array $documents, array $pages): array
    {
        $design = $documents['ui/DESIGN.md'];

        return match ($slug) {
            self::OVERVIEW => [
                ['pages' => array_map(static fn(array $page): array => ['title' => $page['title'], 'url' => self::url($page['slug']), 'summary' => $page['summary']], $pages)],
                [],
            ],
            'color' => [self::color($root, $tokens, $design), [DesignTokens::THEME, DesignTokens::SEMANTICS, self::TONE]],
            'accents' => [
                [
                    'moods' => $tokens->moods(),
                    'accents' => $tokens->accents(),
                    'defaultAccent' => $tokens->defaultAccent(),
                    'targets' => $tokens->targets(),
                    'derivations' => $tokens->accentDerivations(),
                ],
                [DesignTokens::THEME, DesignTokens::PALETTE, DesignTokens::SEMANTICS],
            ],
            'type' => [['roles' => self::typeRoles($tokens, $design)], [DesignTokens::TYPE, self::SITE_STYLESHEET]],
            'layout' => [
                [
                    'spacing' => $tokens->scale('space', $design->items(['Layout', 'Spacing'])),
                    'radii' => $tokens->scale('radius', $design->items(['Layout', 'Border radius'])),
                ],
                [DesignTokens::THEME],
            ],
            'motion' => [['rows' => $tokens->motion($design), 'durations' => self::durations($root, $tokens)], [DesignTokens::THEME, 'ui/site/assets', self::APPLICATION]],
            'logo' => [['marks' => self::marks($root)], array_map(static fn(string $file): string => self::MARKS . '/' . $file, AssetPublication::MARKS)],
            'website' => [self::website($root, $tokens, $design), [self::SITE_STYLESHEET, self::TONE, DesignTokens::THEME, DesignTokens::TYPE]],
            default => [[], []],
        };
    }

    /**
     * Shared with the typography page.
     *
     * @return array<string, array<string, mixed>> the type roles with a sample each
     */
    public static function typeRoles(DesignTokens $tokens, DesignDocument $design): array
    {
        $roles = $tokens->typeRoles($design);
        $unsampled = array_diff_key($roles, self::TYPE_SAMPLES) + array_diff_key(self::TYPE_SAMPLES, $roles);
        if ($unsampled !== []) {
            throw new RuntimeException('Each type role needs one sample text; these differ: ' . implode(', ', array_keys($unsampled)) . '.');
        }
        foreach (array_keys($roles) as $role) {
            $roles[$role]['sample'] = self::TYPE_SAMPLES[$role];
        }

        return $roles;
    }

    /** @return array<string, mixed> */
    private static function color(string $root, DesignTokens $tokens, DesignDocument $design): array
    {
        $moods = array_keys($tokens->moods());
        $roles = array_map(static fn(array $role): array => [
            'use' => $role['use'],
            'detail' => $role['detail'],
            'variables' => array_map(static fn(string $variable): array => [
                'name' => $variable,
                'values' => array_combine($moods, array_map(static fn(string $mood): string => $tokens->colors($mood)[$variable], $moods)),
            ], $role['variables']),
        ], $tokens->colorRoles($design));

        return [
            'moods' => $tokens->moods(),
            'defaultAccent' => $tokens->defaultAccent(),
            'roles' => $roles,
            'appForeground' => $tokens->colors('dark')['--color-foreground'],
            'siteForeground' => self::tone($root),
        ];
    }

    /**
     * Each duration token with the website stylesheets and the application files that use it.
     *
     * @return list<array{name: string, value: string, website: list<string>, application: list<string>}>
     */
    private static function durations(string $root, DesignTokens $tokens): array
    {
        $files = [
            'website' => glob($root . '/ui/site/assets/*.css') ?: [],
            'application' => self::files($root . '/' . self::APPLICATION, ['css', 'ts', 'tsx']),
        ];
        $durations = [];
        foreach ($tokens->durations() as $name => $value) {
            $users = array_map(
                static fn(array $paths): array => array_values(array_filter($paths, static fn(string $path): bool => str_contains((string) file_get_contents($path), 'var(' . $name . ')')))
                    |> (static fn(array $paths): array => array_map(static fn(string $path): string => substr($path, strlen($root) + 1), $paths)),
                $files,
            );
            $durations[] = ['name' => $name, 'value' => $value, 'website' => $users['website'], 'application' => $users['application']];
        }

        return $durations;
    }

    /**
     * @param list<string> $extensions
     * @return list<string>
     */
    private static function files(string $directory, array $extensions): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The cassette marks, each with the size comment its file opens with and the sizes that comment
     * names, largest first.
     *
     * @return list<array{file: string, url: string, comment: string, sizes: list<int>}>
     */
    private static function marks(string $root): array
    {
        $marks = [];
        foreach (AssetPublication::MARKS as $file) {
            $path = self::MARKS . '/' . $file;
            $svg = file_get_contents($root . '/' . $path) ?: throw new RuntimeException('The mark ' . $path . ' is missing.');
            if (preg_match('/<!--\s*(.*?)\s*-->/s', $svg, $comment) !== 1 || preg_match('/(\d+)(?:-(\d+))?px/', $comment[1], $range) !== 1) {
                throw new RuntimeException($path . ' must open with a comment that names the sizes it is drawn for, such as "64-128px".');
            }
            $sizes = array_map(intval(...), array_slice($range, 1)) |> array_unique(...);
            rsort($sizes);
            $marks[] = ['file' => $file, 'url' => '/assets/site/' . $file, 'comment' => $comment[1], 'sizes' => $sizes];
        }

        return $marks;
    }

    /** @return array<string, mixed> */
    private static function website(string $root, DesignTokens $tokens, DesignDocument $design): array
    {
        $site = file_get_contents($root . '/' . self::SITE_STYLESHEET) ?: throw new RuntimeException('The site stylesheet ' . self::SITE_STYLESHEET . ' is missing.');
        $declaration = static fn(string $selector, string $property): string => DesignTokens::declaration($site, self::SITE_STYLESHEET, $selector, $property);
        $roles = $tokens->typeRoles($design);
        $foreground = self::tone($root);

        return [
            'departures' => [
                [
                    'subject' => 'Body text',
                    'website' => $declaration('body', 'font'),
                    'application' => 'body role: ' . $roles['body']['desktop'] . ', line-height ' . $roles['body']['lineHeight'],
                    'source' => self::SITE_STYLESHEET . ' › body',
                ],
                [
                    'subject' => 'Eyebrow labels',
                    'website' => $declaration('.eyebrow', 'font-size') . ', ' . $declaration('.eyebrow', 'font-weight') . ', tracking ' . $declaration('.eyebrow', 'letter-spacing') . ', ' . $declaration('.eyebrow', 'text-transform'),
                    'application' => 'label role: ' . $roles['label']['desktop'] . ', ' . $roles['label']['weight'] . ', tracking ' . $roles['label']['tracking'] . ', ' . $roles['label']['transform'],
                    'source' => self::SITE_STYLESHEET . ' › .eyebrow',
                ],
                [
                    'subject' => 'Moods',
                    'website' => 'dark and ' . AssetPublication::LIGHT_MOOD,
                    'application' => array_keys($tokens->moods()) |> (static fn(array $moods): string => implode(', ', $moods)),
                    'source' => 'AssetPublication::LIGHT_MOOD',
                ],
                [
                    'subject' => 'Dark foreground',
                    'website' => $foreground,
                    'application' => $tokens->colors('dark')['--color-foreground'],
                    'source' => self::TONE,
                ],
            ],
            'lightMood' => AssetPublication::LIGHT_MOOD,
            'siteForeground' => $foreground,
        ];
    }

    /** The website's softened dark foreground. */
    private static function tone(string $root): string
    {
        $tone = file_get_contents($root . '/' . self::TONE) ?: throw new RuntimeException('The site theme tone ' . self::TONE . ' is missing.');

        return DesignTokens::declaration($tone, self::TONE, self::DARK_TONE, '--color-foreground');
    }
}
