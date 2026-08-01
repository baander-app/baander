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
        $renditions = $this->buildRenditions([
            'p360' => QualityTier::p360(),
            'p720' => QualityTier::p720(),
            'p1080' => QualityTier::p1080(),
            'p4k' => QualityTier::p4K(),
        ]);

        $output = $this->generator->generate($renditions, [], 4.0);
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

        // Verify video AdaptationSet
        $adaptationSets = $period->AdaptationSet;
        $videoAdaptationSet = $adaptationSets[0];
        $this->assertSame('video/mp4', (string) $videoAdaptationSet['mimeType']);
        $this->assertSame('video', (string) $videoAdaptationSet['contentType']);
        $this->assertSame('true', (string) $videoAdaptationSet['segmentAlignment']);
        $this->assertSame('1', (string) $videoAdaptationSet['startWithSAP']);

        // Verify all 4 representations are present
        $representations = $videoAdaptationSet->Representation;
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
            'p720' => $this->buildRendition(
                QualityTier::p720(),
                [
                    0 => ['url' => '/seg/seg_000000.m4s', 'duration' => 2.0],
                    1 => ['url' => '/seg/seg_000001.m4s', 'duration' => 3.5],
                    2 => ['url' => '/seg/seg_000002.m4s', 'duration' => 1.0],
                ],
                publicId: 'video-seg-test',
            ),
        ];

        $output = $this->generator->generate($renditions, [], 6.5);
        $xml = new \SimpleXMLElement($output);

        $segmentList = $xml->Period->AdaptationSet->Representation->SegmentList;
        $initialization = $segmentList->Initialization;
        $this->assertSame('/api/stream/video-seg-test/init.mp4', (string) $initialization['sourceURL']);

        $segmentTimeline = $segmentList->SegmentTimeline;
        $segments = $segmentTimeline->S;
        $this->assertCount(3, $segments);

        // Durations in milliseconds
        $this->assertSame('2000', (string) $segments[0]['d']);
        $this->assertSame('0', (string) $segments[0]['t']); // First segment has t=0

        $this->assertSame('3500', (string) $segments[1]['d']);
        $this->assertEmpty((string) $segments[1]['t']); // Subsequent segments have no t

        $this->assertSame('1000', (string) $segments[2]['d']);
        $this->assertEmpty((string) $segments[2]['t']);

        // SegmentURLs follow the timeline
        $segmentUrls = $segmentList->SegmentURL;
        $this->assertCount(3, $segmentUrls);
        $this->assertSame('/seg/seg_000000.m4s', (string) $segmentUrls[0]['media']);
    }

    public function testNoCompletedSegmentsReturnsMpdWithEmptySegmentTimeline(): void
    {
        $renditions = [
            'p720' => $this->buildRendition(QualityTier::p720(), []),
        ];

        $output = $this->generator->generate($renditions, [], 0.0);
        $xml = new \SimpleXMLElement($output);

        // Period should exist
        $period = $xml->Period;
        $this->assertCount(1, $period);

        // Representation should exist but SegmentTimeline should have no S elements
        $representation = $period->AdaptationSet->Representation;
        $this->assertCount(1, $representation);

        $timeline = $representation->SegmentList->SegmentTimeline;
        $this->assertCount(0, $timeline->S);
    }

    public function testSingleQualityTierReturnsSingleRepresentation(): void
    {
        $renditions = [
            'p1080' => $this->buildRendition(
                QualityTier::p1080(),
                [
                    0 => ['url' => '/seg/seg_000000.m4s', 'duration' => 4.0],
                ],
                videoCodec: 'hvc1.1.6.L120.B0',
            ),
        ];

        $output = $this->generator->generate($renditions, [], 4.0);
        $xml = new \SimpleXMLElement($output);

        $representations = $xml->Period->AdaptationSet->Representation;
        $this->assertCount(1, $representations);

        $rep = $representations[0];
        $this->assertSame('p1080', (string) $rep['id']);
        $this->assertSame('5000000', (string) $rep['bandwidth']);
        $this->assertSame('1920', (string) $rep['width']);
        $this->assertSame('1080', (string) $rep['height']);
        // Video Representation only contains the video codec
        $this->assertSame('hvc1.1.6.L120.B0', (string) $rep['codecs']);
    }

    public function testGeneratedXmlIsWellFormed(): void
    {
        $renditions = $this->buildRenditions([
            'p480' => QualityTier::p480(),
            'p1440' => QualityTier::p1440(),
        ]);

        $output = $this->generator->generate($renditions, [], 6.0);

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
        $output = $this->generator->generate([], [], 0.0);

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

    public function testVideoCodecsAttributeContainsOnlyVideoCodec(): void
    {
        $renditions = [
            'p720' => $this->buildRendition(
                QualityTier::p720(),
                [
                    0 => ['url' => '/seg/seg_000000.m4s', 'duration' => 2.0],
                ],
                videoCodec: 'avc1.64001f',
            ),
        ];

        $output = $this->generator->generate($renditions, [], 2.0);
        $xml = new \SimpleXMLElement($output);

        $codecs = (string) $xml->Period->AdaptationSet->Representation['codecs'];
        $this->assertSame('avc1.64001f', $codecs);
    }

    public function testAudioAdaptationSetIsCreatedForAudioTracks(): void
    {
        $renditions = [
            'p720' => $this->buildRendition(
                QualityTier::p720(),
                [
                    0 => ['url' => '/seg/seg_000000.m4s', 'duration' => 2.0],
                ],
            ),
        ];

        $audioAdaptations = [
            'en' => [
                'language' => 'en',
                'init_url' => '/api/stream/video-codec/audio/en/init.mp4',
                'segment_map' => [
                    0 => ['url' => '/audio/en/seg_000000.m4s', 'duration' => 2.0],
                ],
                'codec_rfc6381' => 'mp4a.40.2',
            ],
        ];

        $output = $this->generator->generate($renditions, $audioAdaptations, 2.0);
        $xml = new \SimpleXMLElement($output);

        $adaptationSets = $xml->Period->AdaptationSet;
        $this->assertCount(2, $adaptationSets);

        $audioAdaptationSet = $adaptationSets[1];
        $this->assertSame('audio/mp4', (string) $audioAdaptationSet['mimeType']);
        $this->assertSame('audio', (string) $audioAdaptationSet['contentType']);
        $this->assertSame('en', (string) $audioAdaptationSet['lang']);

        $audioRepresentation = $audioAdaptationSet->Representation;
        $this->assertSame('audio_en', (string) $audioRepresentation['id']);
        $this->assertSame('mp4a.40.2', (string) $audioRepresentation['codecs']);

        $audioChannelConfig = $audioRepresentation->AudioChannelConfiguration;
        $this->assertSame('2', (string) $audioChannelConfig['value']);
    }

    public function testAudioCodecParameterIsUsed(): void
    {
        $renditions = [
            'p720' => $this->buildRendition(
                QualityTier::p720(),
                [
                    0 => ['url' => '/seg/seg_000000.m4s', 'duration' => 2.0],
                ],
            ),
        ];

        $audioAdaptations = [
            'en' => [
                'language' => 'en',
                'init_url' => '/api/stream/video-opus/audio/en/init.mp4',
                'segment_map' => [
                    0 => ['url' => '/audio/en/seg_000000.m4s', 'duration' => 2.0],
                ],
                'codec_rfc6381' => 'Opus',
            ],
        ];

        $output = $this->generator->generate($renditions, $audioAdaptations, 2.0);
        $xml = new \SimpleXMLElement($output);

        $audioRepresentation = $xml->Period->AdaptationSet[1]->Representation;
        $this->assertSame('Opus', (string) $audioRepresentation['codecs']);
    }

    /**
     * @param array<string, QualityTier> $tiers
     * @return array<string, array<string, mixed>>
     */
    private function buildRenditions(array $tiers): array
    {
        $renditions = [];
        foreach ($tiers as $name => $tier) {
            $renditions[$name] = $this->buildRendition($tier);
        }

        return $renditions;
    }

    /**
     * @param array<int, array{url: string, duration: float}> $segmentMap
     * @return array<string, mixed>
     */
    private function buildRendition(
        QualityTier $tier,
        array $segmentMap = [
            0 => ['url' => '/seg/seg_000000.m4s', 'duration' => 2.0],
            1 => ['url' => '/seg/seg_000001.m4s', 'duration' => 2.0],
        ],
        string $videoCodec = 'hvc1.1.6.L93.B0',
        ?string $publicId = null,
    ): array {
        $id = $publicId ?? 'video-' . strtolower($tier->name);

        return [
            'public_id' => $id,
            'quality_tier' => $tier,
            'init_url' => '/api/stream/' . $id . '/init.mp4',
            'segment_map' => $segmentMap,
            'total_duration' => array_sum(array_column($segmentMap, 'duration')),
            'video_codec_rfc6381' => $videoCodec,
            'audio_codec_rfc6381' => 'mp4a.40.2',
        ];
    }
}
