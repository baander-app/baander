<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests\Website;

use Baander\Tooling\Website\Publication;
use PHPUnit\Framework\TestCase;

final class PublicationTest extends TestCase
{
    private const string THEME_LINK = '<link rel="stylesheet" href="/assets/site/theme.css">';

    /** Unicode ranges in Fontsource's slicing, trimmed to what the fixtures need. */
    private const array RANGES = [
        'cyrillic' => 'U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116',
        'vietnamese' => 'U+0102-0103,U+0110-0111,U+0128-0129,U+0168-0169,U+01A0-01A1,U+01AF-01B0,U+0300-0301,U+0303-0304,U+0308-0309,U+0323,U+0329,U+1EA0-1EF9,U+20AB',
        'latin-ext' => 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF',
        'latin' => 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD',
    ];

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-site-publication-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/docs/example', 0700, true);
        mkdir($this->directory . '/pagefind', 0700, true);
        file_put_contents($this->directory . '/index.html', 'Home');
        file_put_contents($this->directory . '/docs/example/index.html', 'Chapter');
        file_put_contents($this->directory . '/404.html', 'Not found');
        file_put_contents($this->directory . '/pagefind/pagefind.js', 'Search');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testPackagesTheMatchingPagesAndSearchWithRealNotFoundRouting(): void
    {
        Publication::package($this->directory);
        $output = $this->directory . '/.vercel/output';
        self::assertSame('Chapter', file_get_contents($output . '/static/docs/example/index.html'));
        self::assertSame('Search', file_get_contents($output . '/static/pagefind/pagefind.js'));
        $config = json_decode(file_get_contents($output . '/config.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(3, $config['version']);
        self::assertContains(['handle' => 'filesystem'], $config['routes']);
        self::assertSame(['src' => '/.*', 'dest' => '/404.html', 'status' => 404], $config['routes'][array_key_last($config['routes'])]);
        self::assertDirectoryDoesNotExist($output . '/static/.vercel');
    }

    public function testFontFilesAreCachedForAYearBecauseTheirNamesChangeWithTheirContent(): void
    {
        Publication::package($this->directory);
        $config = json_decode(file_get_contents($this->directory . '/.vercel/output/config.json'), true, flags: JSON_THROW_ON_ERROR);
        $routes = $config['routes'];
        $fonts = array_find_key($routes, static fn(array $route): bool => str_starts_with($route['src'] ?? '', '/assets/site/fonts/'));
        self::assertNotNull($fonts, 'The fonts directory has its own headers route');
        self::assertSame(['Cache-Control' => 'public, max-age=31536000, immutable'], $routes[$fonts]['headers']);
        self::assertTrue($routes[$fonts]['continue'], 'The headers route still lets the file be served');
        self::assertLessThan(array_search(['handle' => 'filesystem'], $routes, true), $fonts);
    }

    public function testSharedAssetsSelfHostLatinGeistUnderContentHashedNamesWithLicenses(): void
    {
        $root = $this->directory . '/source';
        mkdir($root . '/ui/web/public', 0700, true);
        mkdir($root . '/ui/design-tokens/build/web', 0700, true);
        file_put_contents($root . '/ui/web/public/logo.svg', '<svg></svg>');
        file_put_contents($root . '/ui/design-tokens/build/web/theme.css', ':root[data-theme="balanced"]:not([data-accent]) { color-scheme: light; --color-background: #f5f5f5; }');
        mkdir($root . '/ui/site', 0700, true);
        file_put_contents($root . '/ui/site/theme-tone.css', ':root[data-theme="dark"] { --color-foreground: #e6e6e9; }');
        foreach (['geist', 'geist-mono'] as $font) {
            $package = $root . '/ui/site/node_modules/@fontsource-variable/' . $font;
            mkdir($package . '/files', 0700, true);
            file_put_contents($package . '/LICENSE', 'SIL OPEN FONT LICENSE for ' . $font);
            file_put_contents($package . '/files/' . $font . '-latin-wght-normal.woff2', $font . ' latin fixture');
            file_put_contents($package . '/files/' . $font . '-cyrillic-wght-normal.woff2', $font . ' cyrillic fixture');
        }
        Publication::assets($root, $this->directory);
        $fonts = $this->directory . '/assets/site/fonts';
        self::assertFileExists($this->directory . '/assets/site/logo.svg');
        $files = array_map(basename(...), glob($fonts . '/*') ?: []);
        sort($files);
        $hash = static fn(string $content): string => substr(hash('sha256', $content), 0, 16);
        self::assertSame([
            'geist-LICENSE-' . $hash('SIL OPEN FONT LICENSE for geist') . '.txt',
            'geist-latin-wght-normal-' . $hash('geist latin fixture') . '.woff2',
            'geist-mono-LICENSE-' . $hash('SIL OPEN FONT LICENSE for geist-mono') . '.txt',
            'geist-mono-latin-wght-normal-' . $hash('geist-mono latin fixture') . '.woff2',
        ], $files, 'Only the Latin faces and their licenses ship, and no Inter or JetBrains Mono file');
        $css = file_get_contents($this->directory . '/assets/site/theme.css');
        foreach (['Geist Variable' => 'geist', 'Geist Mono Variable' => 'geist-mono'] as $family => $font) {
            $face = '/assets/site/fonts/' . $font . '-latin-wght-normal-' . $hash($font . ' latin fixture') . '.woff2';
            self::assertMatchesRegularExpression(
                '~@font-face \{[^}]*font-family: "' . $family . '";[^}]*font-weight: 100 900;[^}]*font-display: swap;[^}]*src: url\(\'' . preg_quote($face, '~') . '\'\) format\(\'woff2\'\);[^}]*\}~',
                $css,
            );
        }
        self::assertStringContainsString('@media (prefers-color-scheme: light)', $css);
        self::assertStringContainsString(':root:not([data-theme])', $css);
        self::assertStringEndsWith(':root[data-theme="dark"] { --color-foreground: #e6e6e9; }', $css, 'The site tone follows the shared tokens');
    }

    public function testAPageGetsTheFirstCoveringFaceOfEachCharacterAlongItsFontStack(): void
    {
        self::assertSame(
            ['noto-sans-sc-50-wght-normal.woff2', 'noto-sans-thai-thai-wght-normal.woff2'],
            $this->publishPageFonts('<p>直 ไทย</p>')['faces'],
            'Han text without a language reaches Noto Sans SC first; no Myanmar, other Han or Noto Latin face ships',
        );
    }

    public function testAnArrowOutsideGeistGetsTheOneNotoSliceThatCoversIt(): void
    {
        $published = $this->publishPageFonts('<nav><a href="https://github.com/baander-app/baander">Source <span aria-hidden="true">↗</span></a></nav>');
        self::assertSame(['noto-sans-sc-90-wght-normal.woff2'], $published['faces']);
        self::assertSame(1, substr_count($published['stylesheet'] ?? '', '@font-face'), 'The page declares no face beyond the shared Latin Geist faces');
        self::assertStringContainsString('font-family: "Noto Sans SC Variable";', $published['stylesheet'] ?? '');
        self::assertStringContainsString('unicode-range: U+2197,U+2199;', $published['stylesheet'] ?? '');
    }

    public function testALatinPageLinksNoFontStylesheet(): void
    {
        $body = '<h1>The quick brown fox — “Dune: Part Two” (2024)</h1><p>Bånder · ‘quotes’</p>';
        $published = $this->publishPageFonts($body);
        self::assertSame([], $published['files'], 'The site-wide Latin Geist face already covers the text');
        self::assertNull($published['stylesheet']);
        self::assertSame(self::page($body), $published['html'], 'The page is left as rendered');
    }

    public function testPagesThatReachTheSameFacesShareOneStylesheet(): void
    {
        $staging = $this->stagePages([
            '/docs/one/' => '<p>Source ↗</p>',
            '/docs/two/' => '<p>Read this page’s source ↗</p>',
            '/three/' => '<p>ไทย</p>',
        ]);
        $links = array_map(fn(string $url): ?string => $this->fontLink($staging . $url . 'index.html'), ['/docs/one/', '/docs/two/', '/three/']);
        self::assertNotNull($links[0]);
        self::assertSame($links[0], $links[1]);
        self::assertNotSame($links[0], $links[2]);
        self::assertCount(2, glob($staging . '/assets/site/fonts/page-*.css') ?: []);
    }

    public function testTheNearestLanguageOrdersTheHanFamilies(): void
    {
        self::assertSame(['noto-sans-jp-30-wght-normal.woff2'], $this->publishPageFonts('<p lang="ja">直</p>')['faces']);
        self::assertSame(['noto-sans-tc-40-wght-normal.woff2'], $this->publishPageFonts('<p lang="zh-Hant">直</p>')['faces']);
        self::assertSame(['noto-sans-kr-20-wght-normal.woff2'], $this->publishPageFonts('<p lang="ko">직 直</p>')['faces']);
        self::assertSame(
            ['noto-sans-sc-50-wght-normal.woff2'],
            $this->publishPageFonts('<div lang="ja"><p lang="en">直</p></div>')['faces'],
            'An element with its own language starts again from the default order',
        );
    }

    public function testGeistSuppliesCyrillicAndVietnameseBeforeNotoSans(): void
    {
        self::assertSame(
            ['geist-cyrillic-wght-normal.woff2', 'geist-vietnamese-wght-normal.woff2'],
            $this->publishPageFonts('<p lang="ru">Сезон 2</p><p lang="vi">Tiếng Việt</p>')['faces'],
        );
    }

    public function testMonoTextFollowsTheMonoStack(): void
    {
        self::assertSame(
            ['geist-mono-cyrillic-wght-normal.woff2', 'noto-sans-jp-30-wght-normal.woff2'],
            $this->publishPageFonts('<p><kbd>Сезон</kbd> <span class="type-mono" lang="ja">続</span></p>')['faces'],
        );
    }

    public function testPageFontsShipUnderContentHashedNamesWithLicensesAndTheirLinkPrecedesTheTheme(): void
    {
        $published = $this->publishPageFonts('<p>直 ไทย</p>');
        $hash = static fn(string $content): string => substr(hash('sha256', $content), 0, 16);
        $stylesheet = (string) $published['stylesheet'];
        self::assertSame([
            'noto-sans-sc-50-wght-normal-' . $hash('noto-sans-sc-50-wght-normal fixture') . '.woff2',
            'noto-sans-sc-LICENSE-' . $hash('SIL OPEN FONT LICENSE for noto-sans-sc') . '.txt',
            'noto-sans-thai-LICENSE-' . $hash('SIL OPEN FONT LICENSE for noto-sans-thai') . '.txt',
            'noto-sans-thai-thai-wght-normal-' . $hash('noto-sans-thai-thai-wght-normal fixture') . '.woff2',
            'page-' . $hash($stylesheet) . '.css',
        ], $published['files']);
        $face = '/assets/site/fonts/noto-sans-sc-50-wght-normal-' . $hash('noto-sans-sc-50-wght-normal fixture') . '.woff2';
        self::assertMatchesRegularExpression(
            '~@font-face \{[^}]*font-family: "Noto Sans SC Variable";[^}]*font-weight: 100 900;[^}]*font-display: swap;[^}]*src: url\(\''
                . preg_quote($face, '~') . '\'\) format\(\'woff2\'\);[^}]*unicode-range: U\+76f4-76f5,U\+9aa8;[^}]*\}~',
            $stylesheet,
        );
        self::assertStringContainsString('font-family: "Noto Sans Thai Variable";', $stylesheet);
        self::assertSame(2, substr_count($stylesheet, '@font-face'));
        self::assertStringContainsString(
            '<link rel="stylesheet" href="/assets/site/fonts/page-' . $hash($stylesheet) . '.css">' . "\n" . '  ' . self::THEME_LINK,
            $published['html'],
            'The page faces are declared before the shared Latin faces, as Fontsource orders a family’s slices',
        );
    }

    public function testSearchResultsGetOneStylesheetForTheIndexedTextInTheInterfaceStack(): void
    {
        $search = '<section id="manual-search" aria-label="Search manuals"></section>';
        $staging = $this->stagePages([
            '/docs/one/' => $search . '<p>直</p><article data-pagefind-body><h1>Flow</h1><p>Interface → Domain</p>'
                . '<a data-pagefind-ignore="all">ไทย</a></article>',
            '/docs/two/' => $search . '<article data-pagefind-body><pre><code>Сезон</code></pre></article>',
            '/' => '<p>続</p>',
        ]);
        $stylesheets = glob($staging . '/assets/site/fonts/search-*.css') ?: [];
        self::assertCount(1, $stylesheets);
        $css = (string) file_get_contents($stylesheets[0]);
        preg_match_all('~url\(\'/assets/site/fonts/([a-z0-9-]+)-[0-9a-f]{16}\.woff2\'\)~', $css, $faces);
        self::assertSame(
            ['geist-cyrillic-wght-normal', 'noto-sans-sc-109-wght-normal'],
            $faces[1],
            'Results set code in the interface stack and show no text outside the indexed body or from an ignored part',
        );
        $path = '/assets/site/fonts/' . basename($stylesheets[0]);
        foreach (['/docs/one/', '/docs/two/'] as $url) {
            self::assertStringContainsString('<section id="manual-search" data-result-fonts="' . $path . '"', (string) file_get_contents($staging . $url . 'index.html'));
        }
        self::assertStringNotContainsString($path, (string) file_get_contents($staging . '/index.html'), 'Pages without search do not name it');
    }

    public function testSiteStylesAndScriptsReadTheSharedFontStacks(): void
    {
        $site = dirname(__DIR__, 4) . '/ui/site';
        $sources = [...glob($site . '/assets/*.css') ?: [], ...glob($site . '/scripts/*.mjs') ?: [], ...glob($site . '/templates/*.php') ?: []];
        self::assertNotEmpty($sources);
        foreach ($sources as $source) {
            self::assertSame(0, preg_match('/--font-(?:sans|heading)\b/', file_get_contents($source)), $source . ' reads a removed font stack');
        }
    }

    /** A rendered page as the site templates shape it: the shared stylesheets in the head, a style rule the subsetter must not read. */
    private static function page(string $body): string
    {
        return '<!doctype html><html lang="en"><head><title>Page</title>' . "\n  " . self::THEME_LINK
            . '<style>p::before { content: "直"; }</style></head><body>' . $body . '</body></html>';
    }

    /**
     * Writes the pages into a fresh staging tree and publishes their fonts.
     *
     * @param array<string,string> $bodies page body by URL
     * @return string the staging directory
     */
    private function stagePages(array $bodies): string
    {
        $root = $this->directory . '/source';
        if (!is_dir($root)) {
            $this->writeFontPackages($root);
        }
        $staging = $this->directory . '/staging-' . bin2hex(random_bytes(4));
        foreach ($bodies as $url => $body) {
            is_dir($staging . $url) || mkdir($staging . $url, 0700, true);
            file_put_contents($staging . $url . 'index.html', self::page($body));
        }
        Publication::pageFonts($root, $staging, '.type-mono');
        return $staging;
    }

    /** The font stylesheet a published page links, if any. */
    private function fontLink(string $page): ?string
    {
        return preg_match('~<link rel="stylesheet" href="(/assets/site/fonts/page-[0-9a-f]{16}\.css)">~', (string) file_get_contents($page), $link) ? $link[1] : null;
    }

    /**
     * Publishes the fonts of one page with the given body and returns the published font files, the faces
     * without their hashes, the page's font stylesheet and the page as published.
     *
     * @return array{files:list<string>,faces:list<string>,stylesheet:?string,html:string}
     */
    private function publishPageFonts(string $body): array
    {
        $staging = $this->stagePages(['/page/' => $body]);
        $files = array_map(basename(...), glob($staging . '/assets/site/fonts/*') ?: []);
        sort($files);
        $faces = array_values(array_filter($files, static fn(string $file): bool => str_ends_with($file, '.woff2')))
            |> (static fn(array $faces): array => preg_replace('/-[0-9a-f]{16}(?=\.woff2$)/', '', $faces));
        $link = $this->fontLink($staging . '/page/index.html');
        return [
            'files' => $files,
            'faces' => $faces,
            'stylesheet' => $link === null ? null : (string) file_get_contents($staging . $link),
            'html' => (string) file_get_contents($staging . '/page/index.html'),
        ];
    }

    /** Writes the shared font stacks and Fontsource-shaped packages for every family they name. */
    private function writeFontPackages(string $root): void
    {
        $default = ['"Noto Sans SC Variable"', '"Noto Sans TC Variable"', '"Noto Sans JP Variable"', '"Noto Sans KR Variable"'];
        $orders = ['' => $default, '-ja' => ['"Noto Sans JP Variable"', '"Noto Sans SC Variable"', '"Noto Sans TC Variable"', '"Noto Sans KR Variable"'],
            '-ko' => ['"Noto Sans KR Variable"', '"Noto Sans SC Variable"', '"Noto Sans TC Variable"', '"Noto Sans JP Variable"'],
            '-hant' => ['"Noto Sans TC Variable"', '"Noto Sans SC Variable"', '"Noto Sans JP Variable"', '"Noto Sans KR Variable"']];
        $stacks = '';
        foreach ($orders as $variant => $han) {
            $scripts = ['"Noto Sans Variable"', '"Noto Sans Thai Variable"', '"Noto Sans Myanmar Variable"', ...$han];
            $stacks .= "  --font-ui{$variant}: " . implode(', ', ['"Geist Variable"', ...$scripts, 'sans-serif']) . ";\n"
                . "  --font-mono{$variant}: " . implode(', ', ['"Geist Mono Variable"', '"Noto Sans Mono Variable"', ...$scripts, 'monospace']) . ";\n";
        }
        mkdir($root . '/ui/design-tokens/build/web', 0700, true);
        file_put_contents($root . '/ui/design-tokens/build/web/theme.css', ":root {\n{$stacks}}\n");
        $standard = static fn(string ...$subsets): array => array_intersect_key(self::RANGES, array_flip($subsets));
        $packages = [
            'geist' => ['Geist Variable', $standard('cyrillic', 'vietnamese', 'latin-ext', 'latin')],
            'geist-mono' => ['Geist Mono Variable', $standard('cyrillic', 'latin')],
            'noto-sans' => ['Noto Sans Variable', $standard('cyrillic', 'vietnamese', 'latin-ext', 'latin')],
            'noto-sans-mono' => ['Noto Sans Mono Variable', $standard('cyrillic', 'latin')],
            'noto-sans-thai' => ['Noto Sans Thai Variable', ['thai' => 'U+0E01-0E5B,U+200C-200D,U+25CC', ...$standard('latin')]],
            'noto-sans-myanmar' => ['Noto Sans Myanmar Variable', ['myanmar' => 'U+1000-109F,U+200C-200D,U+25CC', ...$standard('latin')]],
            'noto-sans-sc' => ['Noto Sans SC Variable', ['60' => 'U+4e00-4e0f', '50' => 'U+76f4-76f5,U+9aa8', '90' => 'U+2197,U+2199', '109' => 'U+2192', ...$standard('latin')]],
            'noto-sans-tc' => ['Noto Sans TC Variable', ['40' => 'U+76f4,U+9aa8', ...$standard('latin')]],
            'noto-sans-jp' => ['Noto Sans JP Variable', ['30' => 'U+30a0-30ff,U+7d9a,U+76f4,U+9aa8,U+2192,U+2197', ...$standard('latin')]],
            'noto-sans-kr' => ['Noto Sans KR Variable', ['20' => 'U+76f4,U+ac00-d7a3', ...$standard('latin')]],
        ];
        foreach ($packages as $package => [$family, $subsets]) {
            $directory = $root . '/ui/site/node_modules/@fontsource-variable/' . $package;
            mkdir($directory . '/files', 0700, true);
            file_put_contents($directory . '/LICENSE', 'SIL OPEN FONT LICENSE for ' . $package);
            $css = '';
            foreach ($subsets as $subset => $range) {
                $name = $package . '-' . $subset . '-wght-normal';
                file_put_contents($directory . '/files/' . $name . '.woff2', $name . ' fixture');
                $css .= "/* {$name} */\n@font-face {\n  font-family: '{$family}';\n  font-style: normal;\n  font-display: swap;\n  font-weight: 100 900;\n"
                    . "  src: url(./files/{$name}.woff2) format('woff2-variations');\n  unicode-range: {$range};\n}\n\n";
            }
            file_put_contents($directory . '/index.css', $css);
        }
    }
}
