<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\HLS;

use App\Transcode\Infrastructure\HLS\ManifestGenerator;
use App\Transcode\Domain\ValueObject\QualityTier;
use PHPUnit\Framework\TestCase;

final class ManifestGeneratorTest extends TestCase
{
    private ManifestGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new ManifestGenerator();
    }

    public function testMediaManifestIncludesRequiredHeaders(): void
    {
        $tier = QualityTier::p720();
        $manifest = $this->generator->generateMediaManifest(
            $tier,
            '/api/stream/xxx/init.mp4',
            [],
        );

        $this->assertStringContainsString('#EXTM3U', $manifest);
        $this->assertStringContainsString('#EXT-X-VERSION:6', $manifest);
        $this->assertStringContainsString('#EXT-X-INDEPENDENT-SEGMENTS', $manifest);
        $this->assertStringContainsString('#EXT-X-TARGETDURATION:6', $manifest);
        $this->assertStringContainsString('#EXT-X-ENDLIST', $manifest);
    }

    public function testMediaManifestIncludesMapWhenSegmentsExist(): void
    {
        $tier = QualityTier::p720();
        $segments = [
            0 => ['path' => '/seg_0.m4s', 'duration' => 6.0],
            1 => ['path' => '/seg_1.m4s', 'duration' => 6.0],
        ];

        $manifest = $this->generator->generateMediaManifest(
            $tier,
            '/api/stream/xxx/init.mp4',
            $segments,
        );

        $this->assertStringContainsString('#EXT-X-MAP:URI="/api/stream/xxx/init.mp4"', $manifest);
        $this->assertStringContainsString('seg_0.m4s', $manifest);
        $this->assertStringContainsString('seg_1.m4s', $manifest);
    }

    public function testMediaManifestSkipsMapWhenNoSegments(): void
    {
        $tier = QualityTier::p720();
        $manifest = $this->generator->generateMediaManifest(
            $tier,
            '/api/stream/xxx/init.mp4',
            [],
        );

        $this->assertStringNotContainsString('#EXT-X-MAP', $manifest);
    }

    public function testMediaManifestSegmentDurationPrecision(): void
    {
        $tier = QualityTier::p1080();
        $segments = [
            0 => ['path' => '/seg_0.m4s', 'duration' => 5.998],
        ];

        $manifest = $this->generator->generateMediaManifest(
            $tier,
            '/init.mp4',
            $segments,
        );

        $this->assertStringContainsString('#EXTINF:5.998000,', $manifest);
    }

    public function testMasterManifestIncludesAllTiers(): void
    {
        $urls = [
            '720p' => '/api/stream/video123/720p/media.m3u8',
            '1080p' => '/api/stream/video123/1080p/media.m3u8',
        ];

        $manifest = $this->generator->generateMasterManifest($urls);

        $this->assertStringContainsString('#EXTM3U', $manifest);
        $this->assertStringContainsString('#EXT-X-VERSION:6', $manifest);
        $this->assertStringContainsString('#EXT-X-INDEPENDENT-SEGMENTS', $manifest);
        $this->assertStringContainsString('BANDWIDTH=2800000', $manifest);
        $this->assertStringContainsString('RESOLUTION=1280x720', $manifest);
        $this->assertStringContainsString('BANDWIDTH=5000000', $manifest);
        $this->assertStringContainsString('RESOLUTION=1920x1080', $manifest);
    }

    public function testMasterManifestIncludesCodecStrings(): void
    {
        $urls = ['1080p' => '/media.m3u8'];
        $audioGroups = [
            ['groupId' => 'aac', 'language' => 'en', 'name' => 'English', 'uri' => '/audio/en/media.m3u8', 'channels' => '2', 'isDefault' => true, 'codec' => 'mp4a.40.2'],
        ];

        $manifest = $this->generator->generateMasterManifest($urls, $audioGroups);

        // 1080p uses L120 per-tier RFC 6381 codec
        $this->assertStringContainsString('hvc1.1.6.L120.B0,mp4a.40.2', $manifest);
    }

    public function testMasterManifestWithSingleTier(): void
    {
        $urls = ['720p' => '/media.m3u8'];
        $manifest = $this->generator->generateMasterManifest($urls);

        $this->assertStringContainsString('/media.m3u8', $manifest);
    }

    public function testMasterManifestWithAudioGroupsUsesParameterizedGroupId(): void
    {
        $urls = ['720p' => '/media.m3u8'];
        $audioGroups = [
            ['groupId' => 'aac', 'language' => 'en', 'name' => 'English', 'uri' => '/audio/en/media.m3u8', 'channels' => '2', 'isDefault' => true, 'codec' => 'mp4a.40.2'],
            ['groupId' => 'aac', 'language' => 'fr', 'name' => 'French', 'uri' => '/audio/fr/media.m3u8', 'channels' => '2', 'isDefault' => false, 'codec' => 'mp4a.40.2'],
        ];

        $manifest = $this->generator->generateMasterManifest($urls, $audioGroups);

        // GROUP-ID should be "aac" (from audio group), not hardcoded
        $this->assertStringContainsString('GROUP-ID="aac"', $manifest);
        // AUDIO should reference the group ID
        $this->assertStringContainsString('AUDIO="aac"', $manifest);
        // Both audio tracks should appear
        $this->assertStringContainsString('LANGUAGE="en"', $manifest);
        $this->assertStringContainsString('LANGUAGE="fr"', $manifest);
    }

    public function testMasterManifestWithSubtitleGroupsUsesParameterizedGroupId(): void
    {
        $urls = ['720p' => '/media.m3u8'];
        $subtitleGroups = [
            ['groupId' => 'subs', 'language' => 'en', 'name' => 'English', 'uri' => '/sub/en/media.m3u8', 'isDefault' => true],
        ];

        $manifest = $this->generator->generateMasterManifest($urls, [], $subtitleGroups);

        $this->assertStringContainsString('GROUP-ID="subs"', $manifest);
        $this->assertStringContainsString('SUBTITLES="subs"', $manifest);
        $this->assertStringContainsString('TYPE=SUBTITLES', $manifest);
    }

    public function testMasterManifestWithoutAudioGroupsHasNoCodecsAppend(): void
    {
        $urls = ['720p' => '/media.m3u8'];

        $manifest = $this->generator->generateMasterManifest($urls);

        // Without audio groups, CODECS should only have video codec
        $this->assertStringContainsString('CODECS="hvc1.1.6.L93.B0"', $manifest);
        $this->assertStringNotContainsString('AUDIO="', $manifest);
        $this->assertStringNotContainsString('SUBTITLES="', $manifest);
    }
}
