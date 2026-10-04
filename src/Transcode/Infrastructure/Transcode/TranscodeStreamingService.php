<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\Service\VideoProcessingRules;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\DASH\DashManifestGenerator;
use App\Transcode\Infrastructure\HLS\ManifestGenerator;
use App\Transcode\Infrastructure\HLS\QualityLadderRenderer;

final class TranscodeStreamingService implements TranscodeStreamingPortInterface
{
    public function __construct(
        private readonly TranscodeJobRepositoryInterface $jobRepository,
        private readonly TranscodeStoragePortInterface $storage,
        private readonly ManifestGenerator $manifestGenerator,
        private readonly DashManifestGenerator $dashManifestGenerator,
        private readonly QualityLadderRenderer $qualityLadderRenderer,
        private readonly StreamAuthPortInterface $streamAuth,
    )
    {
    }

    public function getMasterManifest(Uuid $videoId): string
    {
        $jobs = $this->jobRepository->findActiveByVideo($videoId);
        $mediaManifestUrls = [];
        $subtitleGroups = [];
        $firstJob = null;
        $videoCodec = $this->currentVideoCodec();

        foreach ($jobs as $job) {
            if ($firstJob === null) {
                $firstJob = $job;
            }

            // List every active rendition in the master manifest, even if it has
            // not produced a single segment yet. The playlist is generated from
            // ffprobe data, and segments are produced on demand when the player
            // requests them — hiding a tier until it has segments defeats the
            // on-the-fly design.
            $tierName = $job->getQualityTierName();
            $mediaManifestPath = sprintf(
                '/api/transcode/%s/media.m3u8?tier=%s',
                $job->getPublicId()->toString(),
                $tierName,
            );
            $mediaManifestUrls[$tierName] = $this->streamAuth->signUrl($mediaManifestPath)['url'];
        }

        // Muxed renditions: audio is baked into each variant segment, so there
        // are no EXT-X-MEDIA audio groups. Subtitles remain separate (WebVTT).
        if ($firstJob !== null) {
            $probeData = $firstJob->getProbeData();
            $subtitleLanguages = array_column($probeData['subtitleStreams'] ?? [], 'language');

            foreach ($subtitleLanguages as $language) {
                $subtitlePath = sprintf('/api/transcode/%s/subtitles/%s/media.m3u8', $firstJob->getPublicId()->toString(), $language);
                $subtitleGroups[] = [
                    'language' => $language,
                    'name' => $this->languageName($language),
                    'uri' => $this->streamAuth->signUrl($subtitlePath)['url'],
                    'isDefault' => $language === ($subtitleLanguages[0] ?? 'en'),
                    'groupId' => 'subs',
                ];
            }
        }

        if (empty($mediaManifestUrls)) {
            return $this->manifestGenerator->generateMasterManifest([]);
        }

        // Override the hard-coded HEVC codec strings in QualityTier with the
        // currently configured encoder's RFC6381 identifier.
        $urls = [];
        $codecOverrides = [];
        foreach ($mediaManifestUrls as $tierName => $url) {
            $tier = QualityTier::fromString($tierName);
            $urls[$tierName] = $url;
            $codecOverrides[$tierName] = VideoProcessingRules::codecRfc6381($videoCodec, $tier->height);
        }

        return $this->manifestGenerator->generateMasterManifest($urls, [], $subtitleGroups, $codecOverrides);
    }

    public function getMediaManifest(PublicId $jobPublicId, string $audioProfileName): string
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        if ($job === null) {
            return $this->manifestGenerator->generateMediaManifest(
                QualityTier::p720(),
                '',
                [],
            );
        }

        $tier = QualityTier::fromString($job->getQualityTierName());
        $initSegmentPath = sprintf(
            '/api/transcode/%s/init',
            $job->getPublicId()->toString(),
        );
        $initSegmentUrl = $this->streamAuth->signUrl($initSegmentPath)['url'];

        $signedSegments = [];
        foreach ($this->buildSegmentDurations($job) as $index => $duration) {
            // Query-param segment URL: the playlist does not hardcode the
            // on-disk filename (which now carries rendition identity). The
            // server resolves ?index=N to the correct cached file.
            $segmentPath = sprintf('/api/transcode/%s/segment?index=%d', $job->getPublicId()->toString(), $index);
            $signedSegments[$index] = [
                'url' => $this->streamAuth->signUrl($segmentPath)['url'],
                'duration' => $duration,
            ];
        }

        return $this->manifestGenerator->generateMediaManifest(
            $tier,
            $initSegmentUrl,
            $signedSegments,
        );
    }

    /**
     * @return array<int, float>
     */
    private function buildSegmentDurations(\App\Transcode\Domain\Model\TranscodeJob $job): array
    {
        $segmentMap = $job->getSegmentMap();
        if ($segmentMap !== []) {
            $durations = [];
            foreach ($segmentMap as $index => $segment) {
                $durations[(int) $index] = (float) $segment['duration'];
            }
            ksort($durations, SORT_NUMERIC);

            return $durations;
        }

        $totalSegments = $job->getTotalSegments();
        if ($totalSegments <= 0) {
            return [];
        }

        $probeData = $job->getProbeData();
        $duration = (float) ($probeData['duration'] ?? 0.0);
        $segmentDuration = \App\Transcode\Infrastructure\FFmpeg\SegmentEncoder::getSegmentDuration();

        $durations = [];
        for ($i = 0; $i < $totalSegments; $i++) {
            $end = min(($i + 1) * $segmentDuration, $duration);
            $start = $i * $segmentDuration;
            $durations[$i] = max(0.0, $end - $start);
        }

        return $durations;
    }

    public function getSegment(PublicId $jobPublicId, int $segmentIndex): ?string
    {
        $path = $this->getSegmentPath($jobPublicId, $segmentIndex);
        if ($path === null) {
            return null;
        }

        return file_get_contents($path);
    }

    public function getSegmentPath(PublicId $jobPublicId, int $segmentIndex): ?string
    {
        return $this->resolveVideoSegmentAvailability($jobPublicId, $segmentIndex)['path']
            ?? null;
    }

    /**
     * @return array{jobId: Uuid, tierKey: string, path: string}|null
     */
    public function resolveVideoSegmentAvailability(PublicId $jobPublicId, int $segmentIndex): ?array
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        if ($job === null) {
            return null;
        }

        $totalSegments = $job->getTotalSegments();
        if ($totalSegments > 0 && ($segmentIndex < 0 || $segmentIndex >= $totalSegments)) {
            return null;
        }

        $path = $this->storage->resolveSegmentPath($job->getVideoId(), QualityTier::fromString($job->getQualityTierName()), $segmentIndex);

        return [
            'jobId' => $job->getId(),
            'tierKey' => $job->getQualityTierName(),
            'path' => $path,
        ];
    }

    public function getInitSegment(PublicId $jobPublicId): ?string
    {
        $path = $this->getInitSegmentPath($jobPublicId);
        if ($path === null) {
            return null;
        }

        return file_get_contents($path);
    }

    public function getInitSegmentPath(PublicId $jobPublicId): ?string
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        if ($job === null) {
            return null;
        }

        $path = $job->getInitSegmentPath();
        if ($path === null) {
            $path = $this->storage->resolveInitSegmentPath($job->getVideoId(), QualityTier::fromString($job->getQualityTierName()));
        }

        return $path;
    }

    public function getDashManifest(Uuid $videoId): string
    {
        $jobs = $this->jobRepository->findActiveByVideo($videoId);
        $renditions = [];
        $totalDuration = 0.0;
        $firstJob = null;

        foreach ($jobs as $job) {
            if ($firstJob === null) {
                $firstJob = $job;
            }

            $tier = QualityTier::fromString($job->getQualityTierName());
            $durations = $this->buildSegmentDurations($job);
            $segmentMap = [];
            $publicId = $job->getPublicId()->toString();

            foreach ($durations as $index => $duration) {
                $segmentPath = sprintf('/api/transcode/%s/segment?index=%d', $publicId, $index);
                $segmentMap[$index] = [
                    'url'      => $this->streamAuth->signUrl($segmentPath)['url'],
                    'duration' => $duration,
                ];
            }

            $initPath = sprintf('/api/transcode/%s/init', $publicId);

            $renditions[$job->getQualityTierName()] = [
                'public_id'           => $publicId,
                'quality_tier'        => $tier,
                'init_url'            => $this->streamAuth->signUrl($initPath)['url'],
                'segment_map'         => $segmentMap,
                'total_duration'      => array_sum(array_column($segmentMap, 'duration')),
                'video_codec_rfc6381' => VideoProcessingRules::codecRfc6381($this->currentVideoCodec(), $tier->height),
                'audio_codec_rfc6381' => $this->audioCodecToRfc6381('aac'),
            ];
            $totalDuration = max($totalDuration, $renditions[$job->getQualityTierName()]['total_duration']);
        }

        // Muxed renditions: audio is baked into each segment, so there are no
        // separate audio AdaptationSets. The DASH manifest is video-only
        // AdaptationSets carrying muxed audio.
        return $this->dashManifestGenerator->generate($renditions, [], $totalDuration);
    }

    /**
     * @return list<array{name: string, height: int, width: int, bitrate: int, codec: string}>
     */
    public function getQualityLadderForVideo(Uuid $videoId): array
    {
        $jobs = $this->jobRepository->findActiveByVideo($videoId);
        $tiers = [];

        foreach ($jobs as $job) {
            $tiers[] = QualityTier::fromString($job->getQualityTierName());
        }

        return $this->qualityLadderRenderer->renderAvailableTiers($tiers);
    }

    // --- Subtitle Delivery ---

    public function getSubtitleManifest(PublicId $jobPublicId, string $language): string
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        if ($job === null) {
            return $this->manifestGenerator->generateSubtitleManifest($language, []);
        }

        $subtitleDir = $this->storage->resolveSubtitleDirectory($job->getVideoId(), $language);
        $segments = $this->scanSubtitleSegments($subtitleDir);

        return $this->manifestGenerator->generateSubtitleManifest($language, $segments);
    }

    public function getSubtitleSegment(PublicId $jobPublicId, string $language, string $segmentName): ?string
    {
        $path = $this->getSubtitleSegmentPath($jobPublicId, $language, $segmentName);
        if ($path === null) {
            return null;
        }

        return file_get_contents($path);
    }

    public function getSubtitleSegmentPath(PublicId $jobPublicId, string $language, string $segmentName): ?string
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        if ($job === null) {
            return null;
        }

        $path = $this->storage->resolveSubtitleSegmentPath($job->getVideoId(), $language, $segmentName);
        if (!$this->storage->exists($path)) {
            return null;
        }

        return $path;
    }

    // --- Helpers ---

    /**
     * @return list<array{segmentName: string, duration: float}>
     */
    private function scanSubtitleSegments(string $subtitleDir): array
    {
        if (!is_dir($subtitleDir)) {
            return [];
        }

        $segments = [];
        $files = glob($subtitleDir . '/*.vtt') ?: [];
        sort($files);

        foreach ($files as $file) {
            $name = basename($file, '.vtt');
            $segments[] = [
                'segmentName' => $name,
                'duration' => 6.0,
            ];
        }

        return $segments;
    }

    /**
     * Map an audio codec name to its RFC 6381 codec identifier.
     *
     * @see https://tools.ietf.org/html/rfc6381
     */
    private function audioCodecToRfc6381(string $codec): string
    {
        return match ($codec) {
            'aac', 'aac-lc', 'aac_lc' => 'mp4a.40.2',
            'heaac', 'he-aac', 'heaacv1' => 'mp4a.40.5',
            'heaacv2', 'he-aacv2' => 'mp4a.40.29',
            'opus' => 'Opus',
            default => 'mp4a.40.2', // Fallback: AAC-LC
        };
    }

    private function currentVideoCodec(): string
    {
        return $_ENV['VIDEO_ENCODER'] ?? 'libx265';
    }

    private function languageName(string $code): string
    {
        static $names = [
            'en' => 'English', 'es' => 'Español', 'fr' => 'Français',
            'de' => 'Deutsch', 'it' => 'Italiano', 'pt' => 'Português',
            'ja' => '日本語', 'ko' => '한국어', 'zh' => '中文',
            'ru' => 'Русский', 'ar' => 'العربية', 'hi' => 'हिन्दी',
        ];

        return $names[$code] ?? $code;
    }
}
