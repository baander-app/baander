<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\QoL;

use App\QoL\Application\Port\AllowedQualityTiersPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Infrastructure\QoL\QualityFilteringStreamingDecorator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class QualityFilteringStreamingDecoratorTest extends TestCase
{
    private const HLS = "#EXTM3U\n"
        . "#EXT-X-STREAM-INF:BANDWIDTH=2800000,RESOLUTION=1280x720\n"
        . "/api/stream/abc/media.m3u8?tier=720p\n"
        . "#EXT-X-STREAM-INF:BANDWIDTH=20000000,RESOLUTION=3840x2160\n"
        . "/api/stream/abc/media.m3u8?tier=4K\n";

    private const DASH = '<MPD><Period><AdaptationSet>'
        . '<Representation id="720p" bandwidth="2800000"><BaseURL>a</BaseURL></Representation>'
        . '<Representation id="4K" bandwidth="20000000"><BaseURL>b</BaseURL></Representation>'
        . '</AdaptationSet></Period></MPD>';

    public function testManifestsKeepOnlyAllowedTiers(): void
    {
        $videoId = Uuid::generate();
        $inner = $this->createStub(TranscodeStreamingPortInterface::class);
        $inner->method('getMasterManifest')->willReturn(self::HLS);
        $inner->method('getDashManifest')->willReturn(self::DASH);
        $decorator = $this->decorator($inner, restricted: true);

        self::assertSame(
            "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=2800000,RESOLUTION=1280x720\n/api/stream/abc/media.m3u8?tier=720p\n",
            $decorator->getMasterManifest($videoId),
        );
        self::assertSame(
            '<MPD><Period><AdaptationSet>'
            . '<Representation id="720p" bandwidth="2800000"><BaseURL>a</BaseURL></Representation>'
            . '</AdaptationSet></Period></MPD>',
            $decorator->getDashManifest($videoId),
        );
    }

    public function testManifestsAreUnchangedWhenEveryTierIsAllowed(): void
    {
        $inner = $this->createStub(TranscodeStreamingPortInterface::class);
        $inner->method('getMasterManifest')->willReturn(self::HLS);
        $inner->method('getDashManifest')->willReturn(self::DASH);
        $decorator = $this->decorator($inner, restricted: false);

        self::assertSame(self::HLS, $decorator->getMasterManifest(Uuid::generate()));
        self::assertSame(self::DASH, $decorator->getDashManifest(Uuid::generate()));
    }

    public function testStreamReadsPassThroughUnchanged(): void
    {
        $jobId = new PublicId();
        $inner = $this->createMock(TranscodeStreamingPortInterface::class);
        $inner->expects(self::once())->method('getMediaManifest')->with($jobId, 'stereo')->willReturn('media');
        $inner->expects(self::once())->method('getSegment')->with($jobId, 3)->willReturn('segment');
        $inner->expects(self::once())->method('getSubtitleManifest')->with($jobId, 'en')->willReturn('subtitles');
        $decorator = $this->decorator($inner, restricted: true);

        self::assertSame('media', $decorator->getMediaManifest($jobId, 'stereo'));
        self::assertSame('segment', $decorator->getSegment($jobId, 3));
        self::assertSame('subtitles', $decorator->getSubtitleManifest($jobId, 'en'));
    }

    private function decorator(TranscodeStreamingPortInterface $inner, bool $restricted): QualityFilteringStreamingDecorator
    {
        $allowedTiers = $this->createStub(AllowedQualityTiersPortInterface::class);
        $allowedTiers->method('allowedTierNames')->willReturn($restricted
            ? ['360p', '480p', '720p', '1080p', '1440p']
            : ['360p', '480p', '720p', '1080p', '1440p', '4K']);

        return new QualityFilteringStreamingDecorator($inner, $allowedTiers, new NullLogger());
    }
}
