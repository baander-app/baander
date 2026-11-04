<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests\Website;

use Baander\Tooling\Website\Publication;
use PHPUnit\Framework\TestCase;

final class PublicationTest extends TestCase
{
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

    public function testSiteStylesAndScriptsReadTheSharedFontStacks(): void
    {
        $site = dirname(__DIR__, 4) . '/ui/site';
        $sources = [...glob($site . '/assets/*.css') ?: [], ...glob($site . '/scripts/*.mjs') ?: [], ...glob($site . '/templates/*.php') ?: []];
        self::assertNotEmpty($sources);
        foreach ($sources as $source) {
            self::assertSame(0, preg_match('/--font-(?:sans|heading)\b/', file_get_contents($source)), $source . ' reads a removed font stack');
        }
    }
}
