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

    public function testGrowingTrackUsesActualDurationsAndStableTargetUntilEof(): void
    {
        $segments = [0 => ['url' => '/tracks/audio/segment.m4s?index=0', 'duration' => 6.016]];
        $growing = $this->generator->generateMediaManifest('/tracks/audio/init.mp4', $segments, false);
        self::assertStringContainsString('#EXT-X-PLAYLIST-TYPE:EVENT', $growing);
        self::assertStringContainsString('#EXT-X-TARGETDURATION:7', $growing);
        self::assertStringContainsString('#EXTINF:6.016000,', $growing);
        self::assertStringContainsString('#EXT-X-MEDIA-SEQUENCE:0', $growing);
        self::assertStringContainsString('#EXT-X-MAP:URI="/tracks/audio/init.mp4"', $growing);
        self::assertStringNotContainsString('#EXT-X-ENDLIST', $growing);
        $segments[1] = ['url' => '/tracks/audio/segment.m4s?index=1', 'duration' => 2.026667];
        $complete = $this->generator->generateMediaManifest('/tracks/audio/init.mp4', $segments, true);
        self::assertStringContainsString('#EXT-X-TARGETDURATION:7', $complete);
        self::assertStringContainsString('#EXTINF:2.026667,', $complete);
        self::assertStringContainsString('#EXT-X-ENDLIST', $complete);
    }

    public function testUnpublishedTrackHasNoMapOrInventedSegments(): void
    {
        $manifest = $this->generator->generateMediaManifest('', [], false);
        self::assertStringNotContainsString('#EXT-X-MAP', $manifest);
        self::assertStringNotContainsString('#EXTINF', $manifest);
        self::assertStringNotContainsString('#EXT-X-ENDLIST', $manifest);
    }

    public function testUnexpectedSegmentDurationCannotMutateTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->generator->generateMediaManifest('/init', [['url' => '/segment', 'duration' => 7.5]], false);
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
        $this->assertStringContainsString('BANDWIDTH=' . QualityTier::p720()->maxBitrate, $manifest);
        $this->assertStringContainsString('RESOLUTION=1280x720', $manifest);
        $this->assertStringContainsString('BANDWIDTH=' . QualityTier::p1080()->maxBitrate, $manifest);
        $this->assertStringContainsString('RESOLUTION=1920x1080', $manifest);
    }

    public function testMasterManifestIncludesCodecStrings(): void
    {
        $urls = ['1080p' => '/media.m3u8'];
        $audioGroups = [
            ['groupId' => 'aac', 'language' => 'en', 'name' => 'English', 'uri' => '/audio/en/media.m3u8', 'channels' => '2', 'isDefault' => true, 'codec' => 'mp4a.40.2'],
        ];

        $manifest = $this->generator->generateMasterManifest($urls, $audioGroups, [], ['1080p' => 'avc1.640028,mp4a.40.2']);

        self::assertStringContainsString('CODECS="avc1.640028,mp4a.40.2"', $manifest);
        self::assertStringContainsString('AUDIO="aac"', $manifest);
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

    public function testSubtitleMetadataCannotInjectPlaylistLines(): void
    {
        $manifest = $this->generator->generateMasterManifest(['720p' => '/media.m3u8'], [], [[
            'language' => "en\"\n#EXT-X-ENDLIST", 'name' => "Dialogue \"SDH\"\r\nInjected", 'uri' => '/s2/media.m3u8', 'isForced' => true,
        ]], 'avc1.64001f,mp4a.40.2', ['720p' => 4300000]);
        self::assertStringContainsString('LANGUAGE="und"', $manifest);
        self::assertStringContainsString("NAME=\"Dialogue 'SDH'  Injected\"", $manifest);
        self::assertStringContainsString('FORCED=YES', $manifest);
        self::assertStringContainsString('BANDWIDTH=4300000', $manifest);
        self::assertStringNotContainsString("\n#EXT-X-ENDLIST", $manifest);
        self::assertSame(7, count(explode("\n", $manifest)));
    }
}
