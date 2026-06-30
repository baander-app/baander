<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\DASH;

use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\DASH\DashManifestGenerator;
use PHPUnit\Framework\TestCase;

final class DashManifestGeneratorTest extends TestCase
{
    private DashManifestGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new DashManifestGenerator();
    }

    public function testGenerateProducesValidMpdWithMultipleRepresentations(): void
    {
        $renditions = [
            'p360' => [
                'public_id' => 'video-abc',
                'quality_tier' => QualityTier::p360(),
                'segment_map' => [
                    0 => ['path' => '/segments/p360/seg_000000.m4s', 'duration' => 2.0],
                    1 => ['path' => '/segments/p360/seg_000001.m4s', 'duration' => 2.0],
                ],
                'total_duration' => 4.0,
            ],
            'p720' => [
                'public_id' => 'video-abc',
                'quality_tier' => QualityTier::p720(),
                'segment_map' => [
                    0 => ['path' => '/segments/p720/seg_000000.m4s', 'duration' => 2.0],
                    1 => ['path' => '/segments/p720/seg_000001.m4s', 'duration' => 2.0],
                ],
                'total_duration' => 4.0,
            ],
            'p1080' => [
                'public_id' => 'video-abc',
                'quality_tier' => QualityTier::p1080(),
                'segment_map' => [
                    0 => ['path' => '/segments/p1080/seg_000000.m4s', 'duration' => 2.0],
                    1 => ['path' => '/segments/p1080/seg_000001.m4s', 'duration' => 2.0],
                ],
                'total_duration' => 4.0,
            ],
            'p4k' => [
                'public_id' => 'video-abc',
                'quality_tier' => QualityTier::p4K(),
                'segment_map' => [
                    0 => ['path' => '/segments/p4k/seg_000000.m4s', 'duration' => 2.0],
                    1 => ['path' => '/segments/p4k/seg_000001.m4s', 'duration' => 2.0],
                ],
                'total_duration' => 4.0,
            ],
        ];

        $output = $this->generator->generate($renditions, 4.0);
        $xml = new \SimpleXMLElement($output);

        // Verify root MPD element attributes
        $namespaces = $xml->getNamespaces(true);
        $this->assertArrayHasKey('', $namespaces);
        $this->assertSame('urn:mpeg:dash:schema:mpd:2011', $namespaces['']);
        $this->assertSame('static', (string) $xml['type']);
        $this->assertSame('urn:mpeg:dash:profile:isoff-on-demand:2011', (string) $xml['profiles']);
        $this->assertSame('PT6S', (string) $xml['minBufferTime']);
        $this->assertSame('PT4S', (string) $xml['mediaPresentationDuration']);

        // Verify Period exists
        $period = $xml->Period;
        $this->assertCount(1, $period);

        // Verify AdaptationSet
        $adaptationSet = $period->AdaptationSet;
        $this->assertSame('video/mp4', (string) $adaptationSet['mimeType']);
        $this->assertSame('true', (string) $adaptationSet['segmentAlignment']);
        $this->assertSame('1', (string) $adaptationSet['startWithSAP']);

        // Verify all 4 representations are present
        $representations = $adaptationSet->Representation;
        $this->assertCount(4, $representations);

        // Verify 360p representation attributes
        $p360 = $representations[0];
        $this->assertSame('p360', (string) $p360['id']);
        $this->assertSame('800000', (string) $p360['bandwidth']);
        $this->assertSame('640', (string) $p360['width']);
        $this->assertSame('360', (string) $p360['height']);

        // Verify 4K representation attributes
        $p4k = $representations[3];
        $this->assertSame('p4k', (string) $p4k['id']);
        $this->assertSame('20000000', (string) $p4k['bandwidth']);
        $this->assertSame('3840', (string) $p4k['width']);
        $this->assertSame('2160', (string) $p4k['height']);
    }

    public function testSegmentTimelineContainsCorrectDurations(): void
    {
        $renditions = [
            'p720' => [
                'public_id' => 'video-seg-test',
                'quality_tier' => QualityTier::p720(),
                'segment_map' => [
                    0 => ['path' => '/seg/seg_000000.m4s', 'duration' => 2.0],
                    1 => ['path' => '/seg/seg_000001.m4s', 'duration' => 3.5],
                    2 => ['path' => '/seg/seg_000002.m4s', 'duration' => 1.0],
                ],
                'total_duration' => 6.5,
            ],
        ];

        $output = $this->generator->generate($renditions, 6.5);
        $xml = new \SimpleXMLElement($output);

        $segmentTemplate = $xml->Period->AdaptationSet->Representation->SegmentTemplate;
        $this->assertSame('seg_$Number$.m4s', (string) $segmentTemplate['media']);
        $this->assertSame('init.mp4', (string) $segmentTemplate['initialization']);
        $this->assertSame('0', (string) $segmentTemplate['startNumber']);

        $timeline = $segmentTemplate->SegmentTimeline;
        $segments = $timeline->S;
        $this->assertCount(3, $segments);

        // Durations in milliseconds
        $this->assertSame('2000', (string) $segments[0]['d']);
        $this->assertSame('0', (string) $segments[0]['t']); // First segment has t=0

        $this->assertSame('3500', (string) $segments[1]['d']);
        $this->assertEmpty((string) $segments[1]['t']); // Subsequent segments have no t

        $this->assertSame('1000', (string) $segments[2]['d']);
        $this->assertEmpty((string) $segments[2]['t']);
    }

    public function testNoCompletedSegmentsReturnsMpdWithEmptyPeriod(): void
    {
        $renditions = [
            'p720' => [
                'public_id' => 'video-empty',
                'quality_tier' => QualityTier::p720(),
                'segment_map' => [],
                'total_duration' => 0.0,
            ],
        ];

        $output = $this->generator->generate($renditions, 0.0);
        $xml = new \SimpleXMLElement($output);

        // Period should exist but have no AdaptationSet children with segments
        $period = $xml->Period;
        $this->assertCount(1, $period);

        // Representation should exist but SegmentTimeline should have no S elements
        $representation = $period->AdaptationSet->Representation;
        $this->assertCount(1, $representation);

        $timeline = $representation->SegmentTemplate->SegmentTimeline;
        $this->assertCount(0, $timeline->S);
    }

    public function testSingleQualityTierReturnsSingleRepresentation(): void
    {
        $renditions = [
            'p1080' => [
                'public_id' => 'video-single',
                'quality_tier' => QualityTier::p1080(),
                'segment_map' => [
                    0 => ['path' => '/seg/seg_000000.m4s', 'duration' => 4.0],
                ],
                'total_duration' => 4.0,
            ],
        ];

        $output = $this->generator->generate($renditions, 4.0);
        $xml = new \SimpleXMLElement($output);

        $representations = $xml->Period->AdaptationSet->Representation;
        $this->assertCount(1, $representations);

        $rep = $representations[0];
        $this->assertSame('p1080', (string) $rep['id']);
        $this->assertSame('5000000', (string) $rep['bandwidth']);
        $this->assertSame('1920', (string) $rep['width']);
        $this->assertSame('1080', (string) $rep['height']);
        $this->assertStringContainsString('hvc1.1.6.L120.B0,mp4a.40.2', (string) $rep['codecs']);

        // BaseURL should contain the public_id
        $baseUrl = (string) $rep->BaseURL;
        $this->assertSame('/api/stream/video-single/', $baseUrl);
    }

    public function testGeneratedXmlIsWellFormed(): void
    {
        $renditions = [
            'p480' => [
                'public_id' => 'video-wf',
                'quality_tier' => QualityTier::p480(),
                'segment_map' => [
                    0 => ['path' => '/seg/seg_000000.m4s', 'duration' => 6.0],
                ],
                'total_duration' => 6.0,
            ],
            'p1440' => [
                'public_id' => 'video-wf',
                'quality_tier' => QualityTier::p1440(),
                'segment_map' => [
                    0 => ['path' => '/seg/seg_000000.m4s', 'duration' => 6.0],
                ],
                'total_duration' => 6.0,
            ],
        ];

        $output = $this->generator->generate($renditions, 6.0);

        // SimpleXMLElement constructor throws an exception on malformed XML
        $xml = new \SimpleXMLElement($output);
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);

        // Verify it has the expected structure
        $this->assertCount(1, $xml->Period);
        $this->assertCount(1, $xml->Period->AdaptationSet);
        $this->assertCount(2, $xml->Period->AdaptationSet->Representation);

        // Verify XML declaration is present
        $this->assertStringStartsWith('<?xml', $output);
    }

    public function testEmptyRenditionsReturnsMinimalManifest(): void
    {
        $output = $this->generator->generate([], 0.0);

        $xml = new \SimpleXMLElement($output);

        $namespaces = $xml->getNamespaces(true);
        $this->assertArrayHasKey('', $namespaces);
        $this->assertSame('urn:mpeg:dash:schema:mpd:2011', $namespaces['']);
        $this->assertSame('static', (string) $xml['type']);
        $this->assertEmpty((string) $xml['mediaPresentationDuration']);

        $period = $xml->Period;
        $this->assertCount(1, $period);

        // Empty period should have no AdaptationSet
        $this->assertCount(0, $period->AdaptationSet);
    }

    public function testCodecsAttributeCombinesVideoAndAudio(): void
    {
        $renditions = [
            'p720' => [
                'public_id' => 'video-codec',
                'quality_tier' => QualityTier::p720(),
                'segment_map' => [
                    0 => ['path' => '/seg/seg_000000.m4s', 'duration' => 2.0],
                ],
                'total_duration' => 2.0,
            ],
        ];

        $output = $this->generator->generate($renditions, 2.0);
        $xml = new \SimpleXMLElement($output);

        $codecs = (string) $xml->Period->AdaptationSet->Representation['codecs'];
        $this->assertSame('hvc1.1.6.L93.B0,mp4a.40.2', $codecs);
    }

    public function testCodecsAttributeUsesParameterizedAudioCodec(): void
    {
        $renditions = [
            'p720' => [
                'public_id' => 'video-opus',
                'quality_tier' => QualityTier::p720(),
                'segment_map' => [
                    0 => ['path' => '/seg/seg_000000.m4s', 'duration' => 2.0],
                ],
                'total_duration' => 2.0,
                'audio_codec_rfc6381' => 'Opus',
            ],
        ];

        $output = $this->generator->generate($renditions, 2.0);
        $xml = new \SimpleXMLElement($output);

        $codecs = (string) $xml->Period->AdaptationSet->Representation['codecs'];
        $this->assertSame('hvc1.1.6.L93.B0,Opus', $codecs);
    }
}
