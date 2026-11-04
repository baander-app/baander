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
     * Publishes the font slices a rendered page's own text reaches first in its font stacks, each with its
     * family's license, and puts their rules at the top of that page's stylesheet, so no other page loads them.
     * The site-wide Latin faces are already declared and are not repeated.
     *
     * @param string $monoSelector the page's elements, besides code, that set the mono stack
     */
    public static function pageFonts(string $root, string $staging, string $url, string $stylesheet, string $monoSelector): void
    {
        $page = $staging . $url . 'index.html';
        $html = is_file($page) ? file_get_contents($page) : false;
        $target = $staging . '/assets/site/' . $stylesheet;
        $css = is_file($target) ? file_get_contents($target) : false;
        if ($html === false || $css === false) {
            throw self::missing($html === false ? $page : $target);
        }
        $siteWide = array_map(static fn(string $font): string => $font . '-latin-wght-normal.woff2', self::FONTS);
        $rules = '';
        $packages = [];
        foreach (PageFonts::load($root)->select($html, $monoSelector) as $face) {
            if (in_array(basename($face['file']), $siteWide, true)) {
                continue;
            }
            $path = self::copyHashed($face['file'], $staging, basename($face['file'], '.woff2'), 'woff2');
            $packages[$face['package']] = dirname($face['file'], 2);
            $rules .= self::faceRule($face['family'], $path, $face['range']);
        }
        foreach ($packages as $package => $directory) {
            self::copyHashed($directory . '/LICENSE', $staging, $package . '-LICENSE', 'txt');
        }
        if (file_put_contents($target, $rules . $css) === false) {
            throw new RuntimeException('Cannot write website asset: ' . $target);
        }
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
