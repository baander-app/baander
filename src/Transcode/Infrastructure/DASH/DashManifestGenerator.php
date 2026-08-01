<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\DASH;

use App\Transcode\Domain\ValueObject\QualityTier;

final class DashManifestGenerator
{
    private const MPD_NS = 'urn:mpeg:dash:schema:mpd:2011';

    /**
     * Generate a DASH onDemand manifest for the given video jobs and audio tracks.
     *
     * @param array<string, array{public_id: string, quality_tier: QualityTier, init_url: string, segment_map: array<int, array{url: string, duration: float}>, total_duration: float, video_codec_rfc6381: string, audio_codec_rfc6381: string}> $renditions
     * @param array<string, array{language: string, init_url: string, segment_map: array<int, array{url: string, duration: float}>, codec_rfc6381: string}> $audioAdaptations
     */
    public function generate(array $renditions, array $audioAdaptations = [], float $totalDuration = 0.0): string
    {
        if (empty($renditions)) {
            return $this->emptyManifest();
        }

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $mpd = $xml->createElementNS(self::MPD_NS, 'MPD');
        $mpd->setAttribute('xmlns', self::MPD_NS);
        $mpd->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $mpd->setAttribute('profiles', 'urn:mpeg:dash:profile:isoff-on-demand:2011');
        $mpd->setAttribute('type', 'static');
        $mpd->setAttribute('mediaPresentationDuration', sprintf('PT%dS', (int) ceil($totalDuration)));
        $mpd->setAttribute('minBufferTime', 'PT6S');

        $period = $xml->createElement('Period');
        $period->setAttribute('id', '0');

        // Video AdaptationSet
        $videoAdaptationSet = $xml->createElement('AdaptationSet');
        $videoAdaptationSet->setAttribute('mimeType', 'video/mp4');
        $videoAdaptationSet->setAttribute('contentType', 'video');
        $videoAdaptationSet->setAttribute('segmentAlignment', 'true');
        $videoAdaptationSet->setAttribute('startWithSAP', '1');
        $videoAdaptationSet->setAttribute('subsegmentAlignment', 'true');

        foreach ($renditions as $tierName => $rendition) {
            $tier = $rendition['quality_tier'];

            $representation = $xml->createElement('Representation');
            $representation->setAttribute('id', $tierName);
            $representation->setAttribute('bandwidth', (string) $tier->videoBitrate);
            $representation->setAttribute('width', (string) $tier->width);
            $representation->setAttribute('height', (string) $tier->height);
            $representation->setAttribute('codecs', $rendition['video_codec_rfc6381']);

            $segmentList = $this->buildSegmentList($xml, $rendition['init_url'], $rendition['segment_map']);
            $representation->appendChild($segmentList);
            $videoAdaptationSet->appendChild($representation);
        }

        $period->appendChild($videoAdaptationSet);

        // Audio AdaptationSets
        foreach ($audioAdaptations as $language => $audio) {
            $audioAdaptationSet = $xml->createElement('AdaptationSet');
            $audioAdaptationSet->setAttribute('mimeType', 'audio/mp4');
            $audioAdaptationSet->setAttribute('contentType', 'audio');
            $audioAdaptationSet->setAttribute('lang', $language);
            $audioAdaptationSet->setAttribute('segmentAlignment', 'true');

            $representation = $xml->createElement('Representation');
            $representation->setAttribute('id', sprintf('audio_%s', $language));
            $representation->setAttribute('bandwidth', '128000');
            $representation->setAttribute('audioSamplingRate', '48000');
            $representation->setAttribute('codecs', $audio['codec_rfc6381']);

            $audioChannelConfig = $xml->createElement('AudioChannelConfiguration');
            $audioChannelConfig->setAttribute('schemeIdUri', 'urn:mpeg:dash:23003:3:audio_channel_configuration:2011');
            $audioChannelConfig->setAttribute('value', '2');
            $representation->appendChild($audioChannelConfig);

            $segmentList = $this->buildSegmentList($xml, $audio['init_url'], $audio['segment_map']);
            $representation->appendChild($segmentList);
            $audioAdaptationSet->appendChild($representation);
            $period->appendChild($audioAdaptationSet);
        }

        $mpd->appendChild($period);
        $xml->appendChild($mpd);

        return $xml->saveXML();
    }

    /**
     * @param array<int, array{url: string, duration: float}> $segmentMap
     */
    private function buildSegmentList(\DOMDocument $xml, string $initUrl, array $segmentMap): \DOMElement
    {
        $segmentList = $xml->createElement('SegmentList');
        $segmentList->setAttribute('timescale', '1000');

        $initialization = $xml->createElement('Initialization');
        $initialization->setAttribute('sourceURL', $initUrl);
        $segmentList->appendChild($initialization);

        $segmentTimeline = $xml->createElement('SegmentTimeline');
        $segmentIndex = 0;
        foreach ($segmentMap as $segment) {
            $s = $xml->createElement('S');
            $s->setAttribute('d', (string) round($segment['duration'] * 1000));
            if ($segmentIndex === 0) {
                $s->setAttribute('t', '0');
            }
            $segmentTimeline->appendChild($s);
            $segmentIndex++;
        }
        $segmentList->appendChild($segmentTimeline);

        foreach ($segmentMap as $segment) {
            $segmentUrl = $xml->createElement('SegmentURL');
            $segmentUrl->setAttribute('media', $segment['url']);
            $segmentList->appendChild($segmentUrl);
        }

        return $segmentList;
    }

    private function emptyManifest(): string
    {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $mpd = $xml->createElementNS(self::MPD_NS, 'MPD');
        $mpd->setAttribute('xmlns', self::MPD_NS);
        $mpd->setAttribute('profiles', 'urn:mpeg:dash:profile:isoff-on-demand:2011');
        $mpd->setAttribute('type', 'static');
        $mpd->setAttribute('minBufferTime', 'PT6S');

        $period = $xml->createElement('Period');
        $mpd->appendChild($period);
        $xml->appendChild($mpd);

        return $xml->saveXML();
    }
}
