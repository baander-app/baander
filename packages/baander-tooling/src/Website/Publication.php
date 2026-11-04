<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class Publication
{
    public static function assets(string $root, string $staging): void
    {
        $destination = $staging . '/assets/site';
        self::copy($root . '/ui/web/public/logo.svg', $destination . '/logo.svg');
        file_put_contents($destination . '/theme.css', self::theme($root));
        foreach (['inter' => [400, 500, 600], 'jetbrains-mono' => [400]] as $font => $weights) {
            $package = $root . '/ui/site/node_modules/@fontsource/' . $font;
            foreach ($weights as $weight) {
                $filename = $font . '-latin-' . $weight . '-normal.woff2';
                self::copy($package . '/files/' . $filename, $destination . '/fonts/' . $filename);
            }
            self::copy($package . '/LICENSE', $destination . '/fonts/' . $font . '-LICENSE.txt');
        }
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

    private static function copy(string $source, string $destination): void
    {
        if (!is_file($source)) {
            throw new RuntimeException('Missing website asset: ' . $source . '. Install the site dependencies first.');
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
