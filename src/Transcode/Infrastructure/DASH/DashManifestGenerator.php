<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\DASH;

use App\Transcode\Domain\ValueObject\QualityTier;

final class DashManifestGenerator
{
    private const MPD_NS = 'urn:mpeg:dash:schema:mpd:2011';

    /**
     * Generate a DASH onDemand manifest for the given video jobs.
     *
     * @param array<string, array{public_id: string, quality_tier: QualityTier, segment_map: array<int, array{path: string, duration: float}>, total_duration: float, audio_codec_rfc6381?: string}> $renditions
     */
    public function generate(array $renditions, float $totalDuration = 0.0): string
    {
        if (empty($renditions)) {
            return $this->emptyManifest();
        }

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $mpd = $xml->createElementNS(self::MPD_NS, 'MPD');
        $mpd->setAttribute('xmlns', self::MPD_NS);
        $mpd->setAttribute('profiles', 'urn:mpeg:dash:profile:isoff-on-demand:2011');
        $mpd->setAttribute('type', 'static');
        $mpd->setAttribute('mediaPresentationDuration', sprintf('PT%dS', (int) ceil($totalDuration)));
        $mpd->setAttribute('minBufferTime', 'PT6S');

        $period = $xml->createElement('Period');
        $period->setAttribute('id', '0');

        $adaptationSet = $xml->createElement('AdaptationSet');
        $adaptationSet->setAttribute('mimeType', 'video/mp4');
        $adaptationSet->setAttribute('segmentAlignment', 'true');
        $adaptationSet->setAttribute('startWithSAP', '1');
        $adaptationSet->setAttribute('subsegmentAlignment', 'true');

        foreach ($renditions as $tierName => $rendition) {
            $tier = $rendition['quality_tier'];

            $representation = $xml->createElement('Representation');
            $representation->setAttribute('id', $tierName);
            $representation->setAttribute('bandwidth', (string) $tier->videoBitrate);
            $representation->setAttribute('width', (string) $tier->width);
            $representation->setAttribute('height', (string) $tier->height);
            $representation->setAttribute('codecs', $tier->rfc6381Codec . ',' . ($rendition['audio_codec_rfc6381'] ?? 'mp4a.40.2'));

            // BaseURL for init segment
            $baseUrl = $xml->createElement('BaseURL');
            $baseUrl->textContent = sprintf('/api/stream/%s/', $rendition['public_id']);
            $representation->appendChild($baseUrl);

            // SegmentTemplate
            $segmentTemplate = $xml->createElement('SegmentTemplate');
            $segmentTemplate->setAttribute('media', 'seg_$Number$.m4s');
            $segmentTemplate->setAttribute('initialization', 'init.mp4');
            $segmentTemplate->setAttribute('startNumber', '0');

            // SegmentTimeline
            $segmentTimeline = $xml->createElement('SegmentTimeline');
            $segmentIndex = 0;
            foreach ($rendition['segment_map'] as $index => $segment) {
                $s = $xml->createElement('S');
                $s->setAttribute('d', (string) round($segment['duration'] * 1000)); // milliseconds
                if ($segmentIndex === 0) {
                    $s->setAttribute('t', '0');
                }
                $segmentTimeline->appendChild($s);
                $segmentIndex++;
            }

            $segmentTemplate->appendChild($segmentTimeline);
            $representation->appendChild($segmentTemplate);
            $adaptationSet->appendChild($representation);
        }

        $period->appendChild($adaptationSet);
        $mpd->appendChild($period);
        $xml->appendChild($mpd);

        return $xml->saveXML();
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
