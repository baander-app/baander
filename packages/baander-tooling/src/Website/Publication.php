<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class Publication
{
    /** The Latin variable faces the site self-hosts, by the family names the shared font stacks use. */
    private const array FONTS = ['Geist Variable' => 'geist', 'Geist Mono Variable' => 'geist-mono'];

    /** The family every page preloads; the other Latin face is preloaded only by pages whose text reaches it. */
    private const string PRELOADED_FAMILY = 'Geist Variable';

    /**
     * Installed faces that stand in for each Latin Geist face until it loads, scaled to Geist's advance width
     * and given Geist's line box, so the swap moves no text. Chromium matches local() against installed names
     * and does not follow fontconfig's Arial alias, so the Liberation names are listed as well; each pair
     * shares its metrics, so one set of values serves every platform.
     *
     * Derived for Fontsource @fontsource-variable/geist and geist-mono 5.3.0 (Geist 1.800, Geist Mono 1.701)
     * against Liberation Sans and Liberation Mono 2.1.5. The vertical overrides come from the fonts' tables
     * (fontTools 4.68.0): Geist sets USE_TYPO_METRICS, so its typo ascender 1005, descender -295 and line gap 0
     * on 1000 units per em give the Geist line box, divided by the size adjustment. The size adjustment is the
     * ratio of the rendered advance widths of six operator guide paragraphs, measured at 1000px in Playwright
     * 1.64.1's Chromium 156 with font hinting off, which includes the kerning browsers apply; OS/2 xAvgCharWidth
     * gives 100.24% and letter-frequency weighted advances 105.50%, and neither keeps the line count. The
     * mono pair's ratio is the same by table (600/1000 against 1229/2048) and by measurement.
     */
    private const array FALLBACKS = [
        'Geist Variable' => [
            'family' => 'Geist Fallback',
            'local' => ['Arial', 'Liberation Sans'],
            'metrics' => ['size-adjust' => '102.7646%', 'ascent-override' => '97.7963%', 'descent-override' => '28.7064%', 'line-gap-override' => '0%'],
        ],
        'Geist Mono Variable' => [
            'family' => 'Geist Mono Fallback',
            'local' => ['Courier New', 'Liberation Mono'],
            'metrics' => ['size-adjust' => '99.9837%', 'ascent-override' => '100.5164%', 'descent-override' => '29.5048%', 'line-gap-override' => '0%'],
        ],
    ];

    /** Font file names carry their content hash, so the fonts directory is cached as immutable. */
    private const string FONT_DIRECTORY = '/assets/site/fonts/';

    /** The head link that declares the shared Latin faces; page font links go before it. */
    private const string THEME_LINK = '<link rel="stylesheet" href="/assets/site/theme.css">';

    /** The start of the manual search box, which names the search results' font stylesheet. */
    private const string SEARCH_MOUNT = '<section id="manual-search"';

    /**
     * Copies the branding and the Latin faces, and writes the site theme with the faces declared before it.
     *
     * @return array<string,string> the published path of each Latin face, by family, for pages to preload
     */
    public static function assets(string $root, string $staging): array
    {
        $destination = $staging . '/assets/site';
        self::copy($root . '/ui/web/public/logo.svg', $destination . '/logo.svg');
        $faces = self::latinFaces($root, $staging);
        file_put_contents($destination . '/theme.css', self::fonts($root, $faces) . self::theme($root));
        return $faces;
    }

    /**
     * The site theme: the shared design tokens with the fallback face named after each Geist family in
     * the font stacks, their light values as the system-preference fallback, and the site's softer dark
     * tone. Pages and rendered diagrams both use it.
     */
    public static function theme(string $root): string
    {
        $tokens = file_get_contents($root . '/ui/design-tokens/build/web/theme.css');
        if ($tokens === false || !preg_match('/:root\[data-theme="balanced"\]:not\(\[data-accent\]\)\s*\{([^}]+)\}/', $tokens, $light)) {
            throw new RuntimeException('The shared balanced theme is missing; rebuild design tokens.');
        }
        $tone = file_get_contents($root . '/ui/site/theme-tone.css')
            ?: throw new RuntimeException('The site theme tone is missing: ui/site/theme-tone.css.');
        $fallbacks = array_combine(
            array_map(static fn(string $family): string => '"' . $family . '",', array_keys(self::FALLBACKS)),
            array_map(static fn(string $family, array $fallback): string => '"' . $family . '", "' . $fallback['family'] . '",', array_keys(self::FALLBACKS), self::FALLBACKS),
        );
        $stacks = preg_replace_callback(
            PageFonts::STACK_DECLARATION,
            static fn(array $declaration): string => $declaration[1] . ': ' . strtr($declaration[2], $fallbacks) . ';',
            $tokens,
        );

        return $stacks
            . "\n@media (prefers-color-scheme: light) {\n:root:not([data-theme]) {" . $light[1] . "}\n}\n"
            . $tone;
    }

    public static function package(string $staging): void
    {
        $output = $staging . '/.vercel/output';
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($staging) + 1);
            if ($file->isLink() || str_starts_with($relative, '.')) {
                throw new RuntimeException('Unexpected private path in website output: ' . $relative);
            }
            $files[$relative] = $file->getPathname();
        }
        ksort($files);
        $routes = [
            ['src' => preg_quote(self::FONT_DIRECTORY, '~') . '.+', 'headers' => ['Cache-Control' => 'public, max-age=31536000, immutable'], 'continue' => true],
            ['src' => '/index\\.html', 'headers' => ['Location' => '/'], 'status' => 308],
            ['src' => '/(.+)/index\\.html', 'headers' => ['Location' => '/$1/'], 'status' => 308],
            ['src' => '/', 'dest' => '/index.html'],
        ];
        foreach ($files as $relative => $source) {
            self::copy($source, $output . '/static/' . $relative);
            if ($relative !== 'index.html' && str_ends_with($relative, '/index.html')) {
                $url = '/' . substr($relative, 0, -strlen('index.html'));
                $routes[] = ['src' => preg_quote(rtrim($url, '/'), '~'), 'headers' => ['Location' => $url], 'status' => 308];
                $routes[] = ['src' => preg_quote($url, '~'), 'dest' => '/' . $relative];
            }
        }
        $routes[] = ['handle' => 'filesystem'];
        $routes[] = ['src' => '/.*', 'dest' => '/404.html', 'status' => 404];
        file_put_contents($output . '/config.json', json_encode(['version' => 3, 'routes' => $routes], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * Publishes, for every rendered page, the font slices its own text reaches first in the shared font
     * stacks, each with its family's license, and links them from the page's head ahead of the shared
     * Latin faces. Pages that reach the same slices share one stylesheet; a page the shared Latin faces
     * fully cover links none. Search results show other pages' text, so each search box names one
     * stylesheet for all indexed text, which the search script links when it first shows results. Every
     * page preloads the Latin Geist face; a page whose text reaches the Latin Geist Mono face preloads it too.
     *
     * @param string $monoSelector the site's elements, besides code, kbd, samp and pre, that set the mono stack
     * @param array<string,string> $latinFaces the published Latin faces by family, as the shared assets return them
     */
    public static function pageFonts(string $root, string $staging, string $monoSelector, array $latinFaces): void
    {
        $fonts = PageFonts::load($root);
        $pages = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'html') {
                $pages[$file->getPathname()] = file_get_contents($file->getPathname()) ?: throw self::missing($file->getPathname());
            }
        }
        ksort($pages);
        $stylesheets = [];
        $stylesheet = static function (string $name, array $faces) use ($staging, &$stylesheets): ?string {
            $key = $name . ':' . implode(',', array_column($faces, 'file'));
            return $stylesheets[$key] ??= self::fontStylesheet($staging, $name, $faces);
        };
        $search = $stylesheet('search', $fonts->selectIndexed($pages));
        foreach ($pages as $path => $html) {
            $faces = $fonts->select($html, $monoSelector);
            $own = $stylesheet('page', $faces);
            $links = [
                ...self::preloadLinks($latinFaces, $faces),
                ...($own === null ? [] : ['<link rel="stylesheet" href="' . $own . '">']),
                self::THEME_LINK,
            ];
            $published = self::replaceOnce($html, self::THEME_LINK, implode("\n  ", $links), $path);
            if ($search !== null && str_contains($published, self::SEARCH_MOUNT)) {
                $published = self::replaceOnce($published, self::SEARCH_MOUNT, self::SEARCH_MOUNT . ' data-result-fonts="' . $search . '"', $path);
            }
            if ($published !== $html && file_put_contents($path, $published) === false) {
                throw new RuntimeException('Cannot write website page: ' . $path);
            }
        }
    }

    /**
     * Publishes the faces the shared Latin faces do not already declare, with their licenses, as one
     * content-hashed stylesheet.
     *
     * @param list<array{family:string,package:string,file:string,range:string}> $faces
     * @return ?string the stylesheet's published path, or null when the shared Latin faces declare every face
     */
    private static function fontStylesheet(string $staging, string $name, array $faces): ?string
    {
        $siteWide = array_map(static fn(string $font): string => $font . '-latin-wght-normal.woff2', self::FONTS);
        $rules = '';
        $packages = [];
        foreach ($faces as $face) {
            if (in_array(basename($face['file']), $siteWide, true)) {
                continue;
            }
            $path = self::copyHashed($face['file'], $staging, basename($face['file'], '.woff2'), 'woff2');
            $packages[$face['package']] = dirname($face['file'], 2);
            $rules .= self::faceRule($face['family'], $path, $face['range']);
        }
        if ($rules === '') {
            return null;
        }
        foreach ($packages as $package => $directory) {
            self::copyHashed($directory . '/LICENSE', $staging, $package . '-LICENSE', 'txt');
        }
        $path = self::FONT_DIRECTORY . $name . '-' . substr(hash('sha256', $rules), 0, 16) . '.css';
        $directory = dirname($staging . $path);
        if ((!is_dir($directory) && !mkdir($directory, 0755, true)) || file_put_contents($staging . $path, $rules) === false) {
            throw new RuntimeException('Cannot write website asset: ' . $staging . $path);
        }
        return $path;
    }

    /**
     * The preload links for the Latin faces the page paints with: the interface face on every page, the
     * mono face when the page's text reaches it. Same-origin font preloads need crossorigin, or the browser
     * fetches the file a second time for the stylesheet.
     *
     * @param array<string,string> $latinFaces the published Latin faces by family
     * @param list<array{family:string,package:string,file:string,range:string}> $faces the faces the page's text reaches
     * @return list<string>
     */
    private static function preloadLinks(array $latinFaces, array $faces): array
    {
        $reached = array_map(static fn(array $face): string => basename($face['file']), $faces);
        $links = [];
        foreach ($latinFaces as $family => $path) {
            if ($family === self::PRELOADED_FAMILY || in_array(self::latinFile(self::FONTS[$family]), $reached, true)) {
                $links[] = '<link rel="preload" as="font" type="font/woff2" crossorigin href="' . $path . '">';
            }
        }
        return $links;
    }

    /** Replaces the one occurrence of markup the page templates write. */
    private static function replaceOnce(string $html, string $anchor, string $replacement, string $page): string
    {
        if (substr_count($html, $anchor) !== 1) {
            throw new RuntimeException('Cannot link page fonts: ' . $page . ' must contain ' . $anchor . ' once.');
        }
        return str_replace($anchor, $replacement, $html);
    }

    private static function faceRule(string $family, string $path, ?string $range = null): string
    {
        return "@font-face {\n  font-family: \"{$family}\";\n  font-style: normal;\n  font-weight: 100 900;\n"
            . "  font-display: swap;\n  src: url('{$path}') format('woff2');\n"
            . ($range === null ? '' : "  unicode-range: {$range};\n")
            . "}\n";
    }

    /**
     * Copies each Latin face and its license under content-hashed names.
     *
     * @return array<string,string> the published path of each face, by family
     */
    private static function latinFaces(string $root, string $staging): array
    {
        $faces = [];
        foreach (self::FONTS as $family => $font) {
            $package = $root . '/ui/site/node_modules/@fontsource-variable/' . $font;
            $file = self::latinFile($font);
            $faces[$family] = self::copyHashed($package . '/files/' . $file, $staging, basename($file, '.woff2'), 'woff2');
            self::copyHashed($package . '/LICENSE', $staging, $font . '-LICENSE', 'txt');
        }
        return $faces;
    }

    /** The file name of a Fontsource package's Latin variable slice. */
    private static function latinFile(string $font): string
    {
        return $font . '-latin-wght-normal.woff2';
    }

    /**
     * The rules of the Latin faces, each followed by its fallback face. The fallback covers the characters
     * of the installed Latin slice, read from its package, so other characters still fall through to the
     * Noto faces behind it.
     *
     * @param array<string,string> $faces the published path of each Latin face, by family
     */
    private static function fonts(string $root, array $faces): string
    {
        $packages = PageFonts::load($root);
        $rules = '';
        foreach ($faces as $family => $path) {
            $fallback = self::FALLBACKS[$family];
            $rules .= self::faceRule($family, $path)
                . "@font-face {\n  font-family: \"{$fallback['family']}\";\n"
                . '  src: ' . implode(', ', array_map(static fn(string $name): string => 'local("' . $name . '")', $fallback['local'])) . ";\n";
            foreach ($fallback['metrics'] as $metric => $value) {
                $rules .= "  {$metric}: {$value};\n";
            }
            $font = self::FONTS[$family];
            $rules .= '  unicode-range: ' . $packages->declaredRange($font, self::latinFile($font)) . ";\n}\n";
        }
        return $rules;
    }

    /** @return string the published path of the copy */
    private static function copyHashed(string $source, string $staging, string $name, string $extension): string
    {
        $digest = is_file($source) ? hash_file('sha256', $source) : false;
        if ($digest === false) {
            throw self::missing($source);
        }
        $path = self::FONT_DIRECTORY . $name . '-' . substr($digest, 0, 16) . '.' . $extension;
        self::copy($source, $staging . $path);
        return $path;
    }

    private static function missing(string $source): RuntimeException
    {
        return new RuntimeException('Missing website asset: ' . $source . '. Install the site dependencies first.');
    }

    private static function copy(string $source, string $destination): void
    {
        if (!is_file($source)) {
            throw self::missing($source);
        }
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException('Cannot create website asset directory: ' . $directory);
        }
        if (!copy($source, $destination)) {
            throw new RuntimeException('Cannot copy website asset: ' . $source);
        }
    }
}
