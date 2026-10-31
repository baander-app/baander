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

    public function testSharedAssetsIncludeFontsLicensesAndSystemLightFallback(): void
    {
        $root = $this->directory . '/source';
        mkdir($root . '/ui/web/public', 0700, true);
        mkdir($root . '/ui/design-tokens/build/web', 0700, true);
        file_put_contents($root . '/ui/web/public/logo.svg', '<svg></svg>');
        file_put_contents($root . '/ui/design-tokens/build/web/theme.css', ':root[data-theme="balanced"]:not([data-accent]) { color-scheme: light; --color-background: #f5f5f5; }');
        foreach (['inter' => [400, 500, 600], 'jetbrains-mono' => [400]] as $font => $weights) {
            $package = $root . '/ui/site/node_modules/@fontsource/' . $font;
            mkdir($package . '/files', 0700, true);
            file_put_contents($package . '/LICENSE', 'SIL OPEN FONT LICENSE');
            foreach ($weights as $weight) {
                file_put_contents($package . '/files/' . $font . '-latin-' . $weight . '-normal.woff2', 'font fixture');
            }
        }
        Publication::assets($root, $this->directory);
        self::assertFileExists($this->directory . '/assets/site/logo.svg');
        self::assertFileExists($this->directory . '/assets/site/fonts/inter-latin-600-normal.woff2');
        self::assertFileExists($this->directory . '/assets/site/fonts/jetbrains-mono-latin-400-normal.woff2');
        self::assertStringContainsString('SIL OPEN FONT LICENSE', file_get_contents($this->directory . '/assets/site/fonts/inter-LICENSE.txt'));
        $css = file_get_contents($this->directory . '/assets/site/theme.css');
        self::assertStringContainsString('@media (prefers-color-scheme: light)', $css);
        self::assertStringContainsString(':root:not([data-theme])', $css);
    }
}
