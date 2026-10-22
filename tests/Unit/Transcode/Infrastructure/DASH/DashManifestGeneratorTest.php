<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\DASH;

use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\DASH\DashManifestGenerator;
use App\Transcode\Infrastructure\Packaging\PublishedPresentation;
use App\Transcode\Infrastructure\Packaging\PublishedSegment;
use App\Transcode\Infrastructure\Packaging\PublishedTrack;
use PHPUnit\Framework\TestCase;

final class DashManifestGeneratorTest extends TestCase
{
    public function testExplicitTrackTimelinesPreserveNativeTicksAndPublishOnlySignedOrdinals(): void
    {
        $video = $this->track(MediaTrack::Video, 'avc1.640028', 12288, 1024, [new PublishedSegment(0, 1, 1024, 73728, 'video.m4s')]);
        $audio = $this->track(MediaTrack::Audio, 'mp4a.40.2', 48000, 0, [new PublishedSegment(0, 1, 0, 284672, 'audio.m4s')]);
        $presentation = new PublishedPresentation(false, ['availabilityStartTime' => '2026-10-22T10:00:00Z', 'publishTime' => '2026-10-22T10:00:06Z', 'minimumUpdatePeriod' => 'PT6S'], ['start' => 'PT0S'], []);
        $output = (new DashManifestGenerator())->generate([
            '720p' => ['quality_tier' => QualityTier::p720(), 'track' => $video, 'init_url' => '/tracks/video/init.mp4?generation=3&sig=video', 'segment_urls' => [0 => '/tracks/video/segment.m4s?index=0&generation=3'], 'bandwidth' => 4200000],
        ], ['track' => $audio, 'init_url' => '/tracks/audio/init.mp4', 'segment_urls' => [0 => '/tracks/audio/segment.m4s?index=0'], 'language' => 'en', 'bandwidth' => 384000, 'sample_rate' => 44100, 'channels' => 6], $presentation);
        $xml = new \SimpleXMLElement($output);
        self::assertSame('dynamic', (string) $xml['type']);
        self::assertSame('2026-10-22T10:00:00Z', (string) $xml['availabilityStartTime']);
        self::assertSame('urn:mpeg:dash:profile:full:2011', (string) $xml['profiles']);
        self::assertCount(2, $xml->Period->AdaptationSet);
        $v = $xml->Period->AdaptationSet[0]->Representation;
        self::assertSame('video', (string) $xml->Period->AdaptationSet[0]['contentType']);
        self::assertSame('avc1.640028', (string) $v['codecs']);
        self::assertSame('4200000', (string) $v['bandwidth']);
        self::assertSame('0', (string) $v->SegmentList['startNumber']);
        self::assertSame('12288', (string) $v->SegmentList['timescale']);
        self::assertSame('1024', (string) $v->SegmentList['presentationTimeOffset']);
        self::assertSame('INF', (string) $v->SegmentList['availabilityTimeOffset']);
        self::assertSame('true', (string) $v->SegmentList['availabilityTimeComplete']);
        self::assertSame('1024', (string) $v->SegmentList->SegmentTimeline->S['t']);
        self::assertSame('73728', (string) $v->SegmentList->SegmentTimeline->S['d']);
        self::assertSame('/tracks/video/init.mp4?generation=3&sig=video', (string) $v->SegmentList->Initialization['sourceURL']);
        self::assertCount(1, $v->SegmentList->SegmentURL);
        self::assertSame('/tracks/video/segment.m4s?index=0&generation=3', (string) $v->SegmentList->SegmentURL['media']);
        $a = $xml->Period->AdaptationSet[1]->Representation;
        self::assertSame('audio', (string) $xml->Period->AdaptationSet[1]['contentType']);
        self::assertSame('mp4a.40.2', (string) $a['codecs']);
        self::assertSame('44100', (string) $a['audioSamplingRate']);
        self::assertSame('384000', (string) $a['bandwidth']);
        self::assertSame('6', (string) $a->AudioChannelConfiguration['value']);
        self::assertSame('48000', (string) $a->SegmentList['timescale']);
        self::assertSame('284672', (string) $a->SegmentList->SegmentTimeline->S['d']);
        self::assertStringNotContainsString('SegmentTemplate', $output);
    }

    public function testCompletedSnapshotDropsLiveClockAndRetainsNativeDuration(): void
    {
        $track = $this->track(MediaTrack::Video, 'avc1.640028', 1000, 0, [new PublishedSegment(0, 1, 0, 2010, 'video.m4s')]);
        $xml = new \SimpleXMLElement((new DashManifestGenerator())->generate([
            '720p' => ['quality_tier' => QualityTier::p720(), 'track' => $track, 'init_url' => '/init', 'segment_urls' => [0 => '/segment'], 'bandwidth' => 4000000],
        ], null, new PublishedPresentation(true, ['availabilityStartTime' => '2026-10-22T10:00:00Z', 'minimumUpdatePeriod' => 'PT6S', 'mediaPresentationDuration' => 'PT2.01S'], ['start' => 'PT0S'], [])));
        self::assertSame('static', (string) $xml['type']);
        self::assertSame('PT2.01S', (string) $xml['mediaPresentationDuration']);
        self::assertFalse(isset($xml['availabilityStartTime']));
        self::assertFalse(isset($xml['minimumUpdatePeriod']));
        self::assertFalse(isset($xml->Period->AdaptationSet->Representation->SegmentList['availabilityTimeOffset']));
        self::assertCount(1, $xml->Period->AdaptationSet);
    }

    public function testUnavailableSetHasNoPlayableRepresentation(): void
    {
        $xml = new \SimpleXMLElement((new DashManifestGenerator())->generate([]));
        self::assertSame('static', (string) $xml['type']);
        self::assertCount(0, $xml->Period->AdaptationSet);
    }

    /** @param list<PublishedSegment> $segments */
    private function track(MediaTrack $kind, string $codec, int $timescale, int $offset, array $segments): PublishedTrack
    {
        return new PublishedTrack($kind, $kind->value, $codec, $timescale, $offset, 'init.mp4', $segments, ['frameRate' => '24/1'], []);
    }
}
