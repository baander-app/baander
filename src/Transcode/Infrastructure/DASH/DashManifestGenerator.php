<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\DASH;

use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Packaging\PublishedPresentation;
use App\Transcode\Infrastructure\Packaging\PublishedTrack;

/**
 * @phpstan-type VideoRepresentation array{quality_tier: QualityTier, track: PublishedTrack, init_url: string, segment_urls: array<int, string>, bandwidth: int}
 * @phpstan-type AudioRepresentation array{track: PublishedTrack, init_url: string, segment_urls: array<int, string>, language: string, bandwidth: int, sample_rate: int, channels: int}
 */
final class DashManifestGenerator
{
    private const string MPD_NS = 'urn:mpeg:dash:schema:mpd:2011';

    /** @param array<string, VideoRepresentation> $renditions
     * @param AudioRepresentation|null $audio
     */
    public function generate(array $renditions, ?array $audio = null, ?PublishedPresentation $presentation = null): string
    {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $mpd = $xml->createElementNS(self::MPD_NS, 'MPD');
        foreach ($presentation->mpdAttributes ?? [] as $name => $value) {
            if (in_array($name, ['availabilityStartTime', 'publishTime', 'minimumUpdatePeriod', 'minBufferTime', 'maxSegmentDuration', 'suggestedPresentationDelay', 'mediaPresentationDuration', 'timeShiftBufferDepth'], true)) {
                $mpd->setAttribute($name, $value);
            }
        }
        $mpd->setAttribute('profiles', 'urn:mpeg:dash:profile:full:2011');
        $dynamic = $presentation !== null && !$presentation->complete;
        $mpd->setAttribute('type', $dynamic ? 'dynamic' : 'static');
        if (!$dynamic) {
            foreach (['availabilityStartTime', 'publishTime', 'minimumUpdatePeriod', 'suggestedPresentationDelay', 'timeShiftBufferDepth'] as $name) {
                $mpd->removeAttribute($name);
            }
        }
        if (!$mpd->hasAttribute('minBufferTime')) {
            $mpd->setAttribute('minBufferTime', 'PT1S');
        }
        $period = $xml->createElement('Period');
        foreach ($presentation->periodAttributes ?? [] as $name => $value) {
            if (in_array($name, ['id', 'start', 'duration'], true)) {
                $period->setAttribute($name, $value);
            }
        }
        if (!$period->hasAttribute('id')) {
            $period->setAttribute('id', '0');
        }
        if ($renditions !== []) {
            $video = $xml->createElement('AdaptationSet');
            $video->setAttribute('id', 'video');
            $video->setAttribute('mimeType', 'video/mp4');
            $video->setAttribute('contentType', 'video');
            $video->setAttribute('segmentAlignment', 'true');
            foreach ($renditions as $name => $rendition) {
                $track = $rendition['track'];
                $representation = $this->representation($xml, $name, $track, $rendition['bandwidth']);
                $representation->setAttribute('width', (string) $rendition['quality_tier']->width);
                $representation->setAttribute('height', (string) $rendition['quality_tier']->height);
                $representation->appendChild($this->segmentList($xml, $track, $rendition['init_url'], $rendition['segment_urls'], $dynamic));
                $video->appendChild($representation);
            }
            $period->appendChild($video);
            if ($audio !== null) {
                $adaptation = $xml->createElement('AdaptationSet');
                $adaptation->setAttribute('id', 'audio');
                $adaptation->setAttribute('mimeType', 'audio/mp4');
                $adaptation->setAttribute('contentType', 'audio');
                $adaptation->setAttribute('lang', $audio['language']);
                $representation = $this->representation($xml, 'audio', $audio['track'], $audio['bandwidth']);
                $representation->setAttribute('audioSamplingRate', (string) $audio['sample_rate']);
                $channels = $xml->createElement('AudioChannelConfiguration');
                $channels->setAttribute('schemeIdUri', 'urn:mpeg:dash:23003:3:audio_channel_configuration:2011');
                $channels->setAttribute('value', (string) $audio['channels']);
                $representation->appendChild($channels);
                $representation->appendChild($this->segmentList($xml, $audio['track'], $audio['init_url'], $audio['segment_urls'], $dynamic));
                $adaptation->appendChild($representation);
                $period->appendChild($adaptation);
            }
        }
        if (!$dynamic && $renditions !== []) {
            $end = $audio === null ? 0.0 : $audio['track']->end();
            foreach ($renditions as $rendition) {
                $end = max($end, $rendition['track']->end());
            }
            $mpd->removeAttribute('mediaPresentationDuration');
            $period->setAttribute('duration', sprintf('PT%.6FS', ceil($end * 1000000) / 1000000));
        }
        $mpd->appendChild($period);
        $xml->appendChild($mpd);
        return $xml->saveXML();
    }

    private function representation(\DOMDocument $xml, string $id, PublishedTrack $track, int $bandwidth): \DOMElement
    {
        $element = $xml->createElement('Representation');
        $element->setAttribute('id', $id);
        foreach ($track->representationAttributes as $name => $value) {
            if (in_array($name, ['sar', 'frameRate', 'scanType'], true)) {
                $element->setAttribute($name, $value);
            }
        }
        $element->setAttribute('bandwidth', (string) $bandwidth);
        $element->setAttribute('codecs', $track->codec);
        return $element;
    }

    /** @param array<int, string> $urls */
    private function segmentList(\DOMDocument $xml, PublishedTrack $track, string $initUrl, array $urls, bool $dynamic): \DOMElement
    {
        $list = $xml->createElement('SegmentList');
        $list->setAttribute('timescale', (string) $track->timescale);
        $list->setAttribute('presentationTimeOffset', (string) $track->presentationTimeOffset);
        $list->setAttribute('startNumber', (string) ($track->segments[0]->ordinal ?? 0));
        if ($dynamic) {
            // Every listed resource has closed; accelerated encoding need not wait for its wall-clock media time.
            $list->setAttribute('availabilityTimeOffset', 'INF');
            $list->setAttribute('availabilityTimeComplete', 'true');
        }
        $initialization = $xml->createElement('Initialization');
        $initialization->setAttribute('sourceURL', $initUrl);
        $list->appendChild($initialization);
        $timeline = $xml->createElement('SegmentTimeline');
        foreach ($track->segments as $segment) {
            $s = $xml->createElement('S');
            $s->setAttribute('t', (string) $segment->start);
            $s->setAttribute('d', (string) $segment->duration);
            $timeline->appendChild($s);
        }
        $list->appendChild($timeline);
        foreach ($track->segments as $segment) {
            $url = $xml->createElement('SegmentURL');
            $url->setAttribute('media', $urls[$segment->ordinal]);
            $list->appendChild($url);
        }
        return $list;
    }
}
