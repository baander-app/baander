<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\OpenApi;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class NativeOpenApiUsageTest extends TestCase
{
    public function testApplicationAndTestsUseOnlyNativeOpenApiDeclarations(): void
    {
        $root = dirname(__DIR__, 5);
        $directories = [
            $root . '/src',
            $root . '/tests',
            $root . '/packages/baander-phpstan-rules/src',
            $root . '/packages/baander-phpstan-rules/tests',
        ];
        $deprecated = [];
        foreach ($directories as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                assert($file instanceof SplFileInfo);
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                    if (!is_array($token) || !in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        continue;
                    }
                    $name = ltrim($token[1], '\\');
                    if (str_starts_with($name, 'OpenApi\\Annotations')
                        || str_starts_with($name, 'OpenApi\\Attributes')
                        || in_array($name, [
                            'OpenApi\\Context',
                            'OpenApi\\Serializer',
                            'OpenApi\\Generator',
                            'OpenApi\\Analysis',
                            'OpenApi\\Builder',
                            'OpenApi\\Builder\\Mode',
                        ], true)
                    ) {
                        $deprecated[] = substr($file->getPathname(), strlen($root) + 1) . ':' . $token[2] . ' ' . $name;
                    }
                }
            }
        }
        self::assertSame([], $deprecated, implode("\n", $deprecated));
    }
}
