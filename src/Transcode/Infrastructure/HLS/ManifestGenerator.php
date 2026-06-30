<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\HLS;

use App\Transcode\Domain\ValueObject\QualityTier;

final class ManifestGenerator
{
    /**
     * Generate an HLS v6 master playlist with separate audio and subtitle groups.
     *
     * @param array<string, string> $mediaManifestUrls Map of tier name => video manifest URL
     * @param array<string, array{language: string, name: string, uri: string, channels: string, isDefault: bool}> $audioGroups Audio track definitions
     * @param array<string, array{language: string, name: string, uri: string, isDefault: bool}> $subtitleGroups Subtitle track definitions
     */
    public function generateMasterManifest(
        array $mediaManifestUrls,
        array $audioGroups = [],
        array $subtitleGroups = [],
    ): string {
        $lines = [];
        $lines[] = '#EXTM3U';
        $lines[] = '#EXT-X-VERSION:6';
        $lines[] = '#EXT-X-INDEPENDENT-SEGMENTS';

        // Derive GROUP-IDs from group data (parameterized, not hardcoded)
        $audioGroupId = ($audioGroups[0]['groupId'] ?? 'aac');
        $subtitleGroupId = ($subtitleGroups[0]['groupId'] ?? 'subs');
        $audioCodecRfc6381 = ($audioGroups[0]['codec'] ?? null);

        // Audio groups — parameterized GROUP-ID
        foreach ($audioGroups as $audio) {
            $default = ($audio['isDefault'] ?? false) ? 'YES' : 'NO';
            $autoselect = 'YES';
            $lines[] = sprintf(
                '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="%s",LANGUAGE="%s",NAME="%s",DEFAULT=%s,AUTOSELECT=%s,CHANNELS="%s",URI="%s"',
                $audioGroupId,
                $audio['language'],
                $audio['name'],
                $default,
                $autoselect,
                $audio['channels'] ?? '2',
                $audio['uri'],
            );
        }

        // Subtitle groups — parameterized GROUP-ID
        foreach ($subtitleGroups as $sub) {
            $default = ($sub['isDefault'] ?? false) ? 'YES' : 'NO';
            $autoselect = 'YES';
            $lines[] = sprintf(
                '#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="%s",LANGUAGE="%s",NAME="%s",DEFAULT=%s,AUTOSELECT=%s,URI="%s"',
                $subtitleGroupId,
                $sub['language'],
                $sub['name'],
                $default,
                $autoselect,
                $sub['uri'],
            );
        }

        // Video variants — CODECS includes audio codec, AUDIO/SUBTITLES use parameterized IDs
        foreach ($mediaManifestUrls as $tierName => $url) {
            $tier = QualityTier::fromString($tierName);

            $codecs = $tier->rfc6381Codec;
            if ($audioCodecRfc6381 !== null) {
                $codecs .= ',' . $audioCodecRfc6381;
            }

            $streamInf = sprintf(
                '#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%dx%d,CODECS="%s"',
                $tier->videoBitrate,
                $tier->width,
                $tier->height,
                $codecs,
            );

            if (!empty($audioGroups)) {
                $streamInf .= sprintf(',AUDIO="%s"', $audioGroupId);
            }
            if (!empty($subtitleGroups)) {
                $streamInf .= sprintf(',SUBTITLES="%s"', $subtitleGroupId);
            }

            $lines[] = $streamInf;
            $lines[] = $url;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Generate an HLS v6 video-only media playlist for a single quality tier.
     *
     * Video-only — no audio muxed in. Audio is delivered separately via EXT-X-MEDIA.
     *
     * @param QualityTier $tier Quality tier for this rendition
     * @param string $initSegmentUrl URL to the init segment
     * @param array<int, array{path: string, duration: float}> $segments Map of index => segment info
     * @param float $targetDuration Target segment duration for EXT-X-TARGETDURATION
     */
    public function generateMediaManifest(
        QualityTier $tier,
        string $initSegmentUrl,
        array $segments,
        float $targetDuration = 6.0,
    ): string {
        $lines = [];
        $lines[] = '#EXTM3U';
        $lines[] = '#EXT-X-VERSION:6';
        $lines[] = '#EXT-X-INDEPENDENT-SEGMENTS';
        $lines[] = sprintf('#EXT-X-TARGETDURATION:%d', (int) ceil($targetDuration));

        if (!empty($segments)) {
            $lines[] = '#EXT-X-MAP:URI="' . $initSegmentUrl . '"';
        }

        foreach ($segments as $index => $segment) {
            $duration = round($segment['duration'], 6);
            $lines[] = sprintf('#EXTINF:%.6f,', $duration);
            $lines[] = sprintf('seg_%d.m4s', $index);
        }

        $lines[] = '#EXT-X-ENDLIST';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Generate an HLS v6 audio-only media playlist for a specific language.
     *
     * @param string $language BCP-47 language tag
     * @param string $initSegmentUrl URL to the audio init segment
     * @param array<int, array{path: string, duration: float}> $segments Map of index => segment info
     */
    public function generateAudioManifest(
        string $language,
        string $initSegmentUrl,
        array $segments,
        float $targetDuration = 6.0,
    ): string {
        $lines = [];
        $lines[] = '#EXTM3U';
        $lines[] = '#EXT-X-VERSION:6';
        $lines[] = '#EXT-X-INDEPENDENT-SEGMENTS';
        $lines[] = sprintf('#EXT-X-TARGETDURATION:%d', (int) ceil($targetDuration));

        if (!empty($segments)) {
            $lines[] = '#EXT-X-MAP:URI="' . $initSegmentUrl . '"';
        }

        foreach ($segments as $index => $segment) {
            $duration = round($segment['duration'], 6);
            $lines[] = sprintf('#EXTINF:%.6f,', $duration);
            $lines[] = sprintf('seg_%d.m4s', $index);
        }

        $lines[] = '#EXT-X-ENDLIST';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Generate an HLS v6 subtitle media playlist for a specific language.
     *
     * @param string $language BCP-47 language tag
     * @param array<string, array{segmentName: string, duration: float}> $segments
     */
    public function generateSubtitleManifest(
        string $language,
        array $segments,
        float $targetDuration = 6.0,
    ): string {
        $lines = [];
        $lines[] = '#EXTM3U';
        $lines[] = '#EXT-X-VERSION:6';
        $lines[] = sprintf('#EXT-X-TARGETDURATION:%d', (int) ceil($targetDuration));

        foreach ($segments as $segment) {
            $duration = round($segment['duration'], 6);
            $lines[] = sprintf('#EXTINF:%.6f,', $duration);
            $lines[] = $segment['segmentName'] . '.vtt';
        }

        $lines[] = '#EXT-X-ENDLIST';

        return implode("\n", $lines) . "\n";
    }
}
