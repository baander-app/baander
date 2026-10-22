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
     * @param list<array{language: string, name: string, uri: string, channels?: string, isDefault?: bool, groupId?: string, codec?: string}> $audioGroups Audio track definitions
     * @param list<array{language: string, name: string, uri: string, isDefault?: bool, isForced?: bool, groupId?: string}> $subtitleGroups Subtitle track definitions
     * @param array<string, int> $bandwidths Whole-rendition peak bitrate estimates
     * @param string|array<string, string>|null $videoCodecRfc6381 RFC6381 codec override (per-tier map or single string)
     */
    public function generateMasterManifest(
        array $mediaManifestUrls,
        array $audioGroups = [],
        array $subtitleGroups = [],
        string|array|null $videoCodecRfc6381 = null,
        array $bandwidths = [],
    ): string {
        $lines = [];
        $lines[] = '#EXTM3U';
        $lines[] = '#EXT-X-VERSION:6';
        $lines[] = '#EXT-X-INDEPENDENT-SEGMENTS';

        // Derive GROUP-IDs from group data (parameterized, not hardcoded)
        $audioGroupId = ($audioGroups[0]['groupId'] ?? 'aac');
        $subtitleGroupId = ($subtitleGroups[0]['groupId'] ?? 'subs');

        // Audio groups — parameterized GROUP-ID
        foreach ($audioGroups as $audio) {
            $default = ($audio['isDefault'] ?? false) ? 'YES' : 'NO';
            $autoselect = 'YES';
            $lines[] = sprintf(
                '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="%s",LANGUAGE="%s",NAME="%s",DEFAULT=%s,AUTOSELECT=%s,CHANNELS="%s",URI="%s"',
                self::quoted($audioGroupId),
                self::language($audio['language']),
                self::quoted($audio['name']),
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
                '#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="%s",LANGUAGE="%s",NAME="%s",DEFAULT=%s,AUTOSELECT=%s,FORCED=%s,URI="%s"',
                self::quoted($subtitleGroupId),
                self::language($sub['language']),
                self::quoted($sub['name']),
                $default,
                $autoselect,
                ($sub['isForced'] ?? false) ? 'YES' : 'NO',
                self::quoted($sub['uri']),
            );
        }

        // Video variants — CODECS includes audio codec, AUDIO/SUBTITLES use parameterized IDs
        foreach ($mediaManifestUrls as $tierName => $url) {
            $tier = QualityTier::fromString($tierName);

            $codecs = is_array($videoCodecRfc6381) ? ($videoCodecRfc6381[$tierName] ?? $tier->rfc6381Codec) : ($videoCodecRfc6381 ?? $tier->rfc6381Codec);
            $streamInf = sprintf(
                '#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%dx%d,CODECS="%s"',
                $bandwidths[$tierName] ?? $tier->maxBitrate,
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
     * @param array<int, array{url: string, duration: float}> $segments Published track segments keyed by generation-local ordinal
     */
    public function generateMediaManifest(
        string $initSegmentUrl,
        array $segments,
        bool $complete,
        float $targetDuration = 7.0,
    ): string {
        if (!is_finite($targetDuration) || $targetDuration < 1 || floor($targetDuration) !== $targetDuration) {
            throw new \InvalidArgumentException('HLS target duration must be a positive integer.');
        }
        $lines = ['#EXTM3U', '#EXT-X-VERSION:6', '#EXT-X-PLAYLIST-TYPE:EVENT',
            '#EXT-X-TARGETDURATION:' . (int) $targetDuration];
        if ($segments !== []) {
            $lines[] = '#EXT-X-MEDIA-SEQUENCE:' . array_key_first($segments);
            $lines[] = '#EXT-X-MAP:URI="' . self::quoted($initSegmentUrl) . '"';
        }
        foreach ($segments as $segment) {
            if (!is_finite($segment['duration']) || $segment['duration'] <= 0 || round($segment['duration']) > $targetDuration) {
                throw new \InvalidArgumentException('Published segment duration exceeds the stable HLS target.');
            }
            $lines[] = sprintf('#EXTINF:%.6f,', $segment['duration']);
            $lines[] = $segment['url'];
        }
        if ($complete) {
            $lines[] = '#EXT-X-ENDLIST';
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Generate an HLS v6 subtitle media playlist for a specific language.
     *
     * @param string $language BCP-47 language tag
     * @param list<array{segmentName: string, duration: float, url?: string}> $segments
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
            $lines[] = $segment['url'] ?? $segment['segmentName'] . '.vtt';
        }

        $lines[] = '#EXT-X-ENDLIST';

        return implode("\n", $lines) . "\n";
    }

    private static function quoted(string $value): string
    {
        return str_replace('"', "'", preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '');
    }

    private static function language(string $value): string
    {
        return preg_match('/^[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*$/D', $value) === 1 ? $value : 'und';
    }
}
