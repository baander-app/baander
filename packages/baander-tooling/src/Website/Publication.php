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

    /** Font file names carry their content hash, so the fonts directory is cached as immutable. */
    private const string FONT_DIRECTORY = '/assets/site/fonts/';

    /** The head link that declares the shared Latin faces; page font links go before it. */
    private const string THEME_LINK = '<link rel="stylesheet" href="/assets/site/theme.css">';

    /** The start of the manual search box, which names the search results' font stylesheet. */
    private const string SEARCH_MOUNT = '<section id="manual-search"';

    public static function assets(string $root, string $staging): void
    {
        $destination = $staging . '/assets/site';
        self::copy($root . '/ui/web/public/logo.svg', $destination . '/logo.svg');
        file_put_contents($destination . '/theme.css', self::fonts($root, $staging) . self::theme($root));
    }

    /**
     * The site theme: the shared design tokens, their light values as the system-preference fallback,
     * and the site's softer dark tone. Pages and rendered diagrams both use it.
     */
    public static function theme(string $root): string
    {
        $tokens = file_get_contents($root . '/ui/design-tokens/build/web/theme.css');
        if ($tokens === false || !preg_match('/:root\[data-theme="balanced"\]:not\(\[data-accent\]\)\s*\{([^}]+)\}/', $tokens, $light)) {
            throw new RuntimeException('The shared balanced theme is missing; rebuild design tokens.');
        }
        $tone = file_get_contents($root . '/ui/site/theme-tone.css')
            ?: throw new RuntimeException('The site theme tone is missing: ui/site/theme-tone.css.');

        return $tokens
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
     * stylesheet for all indexed text, which the search script links when it first shows results.
     *
     * @param string $monoSelector the site's elements, besides code, kbd, samp and pre, that set the mono stack
     */
    public static function pageFonts(string $root, string $staging, string $monoSelector): void
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
            $published = $html;
            $own = $stylesheet('page', $fonts->select($html, $monoSelector));
            if ($own !== null) {
                $published = self::replaceOnce($published, self::THEME_LINK, '<link rel="stylesheet" href="' . $own . '">' . "\n  " . self::THEME_LINK, $path);
            }
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

    /** Copies each Latin face and its license under content-hashed names and returns the faces' rules. */
    private static function fonts(string $root, string $staging): string
    {
        $rules = '';
        foreach (self::FONTS as $family => $font) {
            $package = $root . '/ui/site/node_modules/@fontsource-variable/' . $font;
            $face = self::copyHashed($package . '/files/' . $font . '-latin-wght-normal.woff2', $staging, $font . '-latin-wght-normal', 'woff2');
            self::copyHashed($package . '/LICENSE', $staging, $font . '-LICENSE', 'txt');
            $rules .= self::faceRule($family, $face);
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
