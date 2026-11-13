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
     * and given Geist's line box, so the swap moves no text. Each fallback family lists its faces in the order
     * they are declared: a regular face for weights up to 500 and, where the text sets semibold or bold, a
     * bold face for 600 and above, so bold text is set in a real bold cut rather than a smeared regular one
     * with the wrong widths. Chromium matches local() against a face's full or PostScript name, not fontconfig
     * aliases, so the Liberation names stand beside the Arial ones; each pair shares its metrics. Android ships
     * neither Arial nor Liberation Sans, so a Roboto family follows in the interface stacks and is reached only
     * where the first family fails to load. No Android mono face is installed here to derive against, so the
     * mono stack keeps its one fallback family.
     *
     * Derived for Fontsource @fontsource-variable/geist and geist-mono 5.3.0 (Geist 1.800, Geist Mono 1.701)
     * against Liberation Sans and Liberation Mono 2.1.5 and Roboto 2.138. The vertical overrides come from the
     * fonts' tables (fontTools 4.68.0): Geist sets USE_TYPO_METRICS, so its typo ascender 1005, descender -295
     * and line gap 0 on 1000 units per em give the Geist line box, divided by the face's size adjustment; the
     * variable font's MVAR table varies no vertical metric, so the bold instance keeps the same box. The size
     * adjustments were chosen by rendering the homepage and the operator guide in Playwright 1.64.1's Chromium
     * 156 with font hinting off at 390, 820 and 1440px, Geist loaded against Geist blocked, and sweeping each
     * face's value for the fewest heading, paragraph and list item heights that differ. The regular Liberation
     * value is also the ratio of the rendered widths of six body paragraphs at 16px, which the sweep confirmed
     * as the minimum. Bold text wants two ratios, about 99.5% at weight 600 and 101.9% at 700 against
     * Liberation Sans Bold (105.6% and 109% against Roboto Bold); one face takes the middle, which leaves the
     * h1 about 1.5% narrower and the h2 and h3 about 1.5% wider than Geist until it loads. The mono pair's
     * ratio is the same by table (600/1000 against 1229/2048) and by measurement.
     */
    private const array FALLBACKS = [
        'Geist Variable' => [
            'Geist Fallback' => [
                ['weight' => '100 500', 'local' => ['Arial', 'Liberation Sans'], 'metrics' => ['size-adjust' => '102.7646%', 'ascent-override' => '97.7963%', 'descent-override' => '28.7064%', 'line-gap-override' => '0%']],
                ['weight' => '600 900', 'local' => ['Arial Bold', 'Liberation Sans Bold'], 'metrics' => ['size-adjust' => '100.3%', 'ascent-override' => '100.1994%', 'descent-override' => '29.4118%', 'line-gap-override' => '0%']],
            ],
            'Geist Fallback Roboto' => [
                ['weight' => '100 500', 'local' => ['Roboto'], 'metrics' => ['size-adjust' => '103.5%', 'ascent-override' => '97.1014%', 'descent-override' => '28.5024%', 'line-gap-override' => '0%']],
                ['weight' => '600 900', 'local' => ['Roboto Bold'], 'metrics' => ['size-adjust' => '106.6%', 'ascent-override' => '94.2777%', 'descent-override' => '27.6735%', 'line-gap-override' => '0%']],
            ],
        ],
        'Geist Mono Variable' => [
            'Geist Mono Fallback' => [
                ['weight' => '100 900', 'local' => ['Courier New', 'Liberation Mono'], 'metrics' => ['size-adjust' => '99.9837%', 'ascent-override' => '100.5164%', 'descent-override' => '29.5048%', 'line-gap-override' => '0%']],
            ],
        ],
    ];

    /** Font file names carry their content hash, so the fonts directory is cached as immutable. */
    private const string FONT_DIRECTORY = '/assets/site/fonts/';

    /** The head link that declares the shared Latin faces; page font links go before it. */
    private const string THEME_LINK = '<link rel="stylesheet" href="/assets/site/theme.css">';

    /** The start of the manual search box, which names the search results' font stylesheet. */
    private const string SEARCH_MOUNT = '<section id="manual-search"';

    /**
     * Pages that moved, from their former URL to their current one. The former address, with or without its
     * trailing slash, answers with a permanent redirect; nothing below it does, and the sitemap lists only
     * the current URL.
     */
    private const array MOVED_PAGES = ['/baac/' => '/bac/'];

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
     * The site theme: the shared design tokens with the fallback families named after each Geist family in
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
        $quote = static fn(string $family): string => '"' . $family . '",';
        $fallbacks = array_combine(
            array_map($quote, array_keys(self::FALLBACKS)),
            array_map(static fn(string $family, array $families): string => implode(' ', array_map($quote, [$family, ...array_keys($families)])), array_keys(self::FALLBACKS), self::FALLBACKS),
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

    /** The current URL of the page that used to live at $path, or null when no page moved from there. */
    public static function movedTo(string $path): ?string
    {
        foreach (self::MOVED_PAGES as $from => $to) {
            if ($path === $from || $path === rtrim($from, '/')) {
                return $to;
            }
        }
        return null;
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
        foreach (self::MOVED_PAGES as $from => $to) {
            if (isset($files[ltrim($from, '/') . 'index.html'])) {
                throw new RuntimeException('A page is published at the moved address ' . $from);
            }
            $routes[] = ['src' => preg_quote(rtrim($from, '/'), '~') . '/?', 'headers' => ['Location' => $to], 'status' => 308];
        }
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
     * The rules of the Latin faces, each followed by the faces of its fallback families. A fallback covers
     * the characters of the installed Latin slice, read from its package, so other characters still fall
     * through to the Noto faces behind it.
     *
     * @param array<string,string> $faces the published path of each Latin face, by family
     */
    private static function fonts(string $root, array $faces): string
    {
        $packages = PageFonts::load($root);
        $rules = '';
        foreach ($faces as $family => $path) {
            $font = self::FONTS[$family];
            $range = $packages->declaredRange($font, self::latinFile($font));
            $rules .= self::faceRule($family, $path);
            foreach (self::FALLBACKS[$family] as $fallback => $fallbackFaces) {
                foreach ($fallbackFaces as $face) {
                    $rules .= self::localFaceRule($fallback, $face, $range);
                }
            }
        }
        return $rules;
    }

    /**
     * The rule of one installed face standing in for a Geist face over a weight range, with the metrics
     * that fit it to Geist.
     *
     * @param array{weight:string,local:list<string>,metrics:array<string,string>} $face
     */
    private static function localFaceRule(string $family, array $face, string $range): string
    {
        $sources = implode(', ', array_map(static fn(string $name): string => 'local("' . $name . '")', $face['local']));
        $metrics = implode('', array_map(static fn(string $metric, string $value): string => "  {$metric}: {$value};\n", array_keys($face['metrics']), $face['metrics']));
        return "@font-face {\n  font-family: \"{$family}\";\n  font-weight: {$face['weight']};\n  src: {$sources};\n{$metrics}  unicode-range: {$range};\n}\n";
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
