<?php

declare(strict_types=1);

namespace Baander\Configuration\Tests;

use Baander\Configuration\ToolingConfiguration;
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
        self::assertSame('redis:7-alpine@sha256:7f7d30b7fdd09467bac58198a1f166dd69789d348d4fe859cccb20e0cf287fbe', $images->role('developmentRedis')->reference);
        self::assertSame('redis/redis-stack-server:edge@sha256:4225f5c02d336b1bac3a1b7d7be2258e2ff1c2e77c856e82e2e5723f77679825', $images->role('fixtureRedis')->reference);
        self::assertSame('mcr.microsoft.com/playwright:v1.64.1-noble@sha256:2f05f8a734509a7b3f26a912639488ee08f58a7b292d14d62271bcff9038768f', $images->role('browser')->reference);
        self::assertSame('ghcr.io/baander-app/ffmpeg-baander-static@sha256:762d62b2ca6de0fd5d1502b7d1df7422718fb73e70d2c92ee9d707493f347925', $images->role('ffmpeg')->reference);
        self::assertSame('ffmpeg.baander.app/static@sha256:' . str_repeat('b', 64), ToolingConfiguration::resolve(environment: ['BAANDER_FFMPEG_IMAGE' => 'ffmpeg.baander.app/static@sha256:' . str_repeat('b', 64)])->images->role('ffmpeg')->reference);
        $images->role('browser')->assertPlaywrightVersion('1.64.1');
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
