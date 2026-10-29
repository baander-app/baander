<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Configuration\ToolingConfiguration;
use PHPUnit\Framework\TestCase;

final class ImageCatalogTest extends TestCase
{
    public function testMalformedImageReferencesAreRejectedBeforeExecution(): void
    {
        foreach (['redis:', 'mirror.baander.app//redis:7', 'https://mirror.baander.app/redis:7', 'redis@sha256:abc', 'redis:7 unexpected'] as $reference) {
            try {
                ToolingConfiguration::resolve(local: ['images' => ['developmentRedis' => ['reference' => $reference]]]);
                self::fail('Accepted malformed image reference: ' . $reference);
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }
    public function testDefaultsPreserveDistinctRedisRolesAndBrowserVersion(): void
    {
        $images = ToolingConfiguration::resolve()->images;
        self::assertSame('redis:7-alpine', $images->role('developmentRedis')->reference);
        self::assertSame('redis/redis-stack-server:edge', $images->role('fixtureRedis')->reference);
        self::assertSame('mcr.microsoft.com/playwright:v1.60.0-noble', $images->role('browser')->reference);
        $images->role('browser')->assertPlaywrightVersion('1.60.0');
    }

    public function testMirrorDigestAndDeclaredCompatibilityNeedNoPhpChanges(): void
    {
        $reference = 'mirror.baander.app/browser@sha256:' . str_repeat('a', 64);
        $images = ToolingConfiguration::resolve(local: ['images' => ['browser' => ['reference' => $reference, 'playwrightVersion' => '1.61.0']]])->images;
        self::assertSame($reference, $images->role('browser')->reference);
        $images->role('browser')->assertPlaywrightVersion('1.61.0');
    }

    public function testCustomBrowserMustDeclareCompatibility(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ToolingConfiguration::resolve(local: ['images' => ['browser' => ['reference' => 'mirror.baander.app/browser:custom']]]);
    }

    public function testIncompatibleBrowserPackageIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ToolingConfiguration::resolve()->images->role('browser')->assertPlaywrightVersion('1.59.0');
    }
}
