<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
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
    )
    {
    }

    public function getMasterManifest(Uuid $videoId): string
    {
        $jobs = $this->jobRepository->findActiveByVideo($videoId);
        $mediaManifestUrls = [];
        $audioGroups = [];
        $subtitleGroups = [];
        $audioLanguages = [];

        foreach ($jobs as $job) {
            if ($job->getStatus()->value === 'completed' || $job->getCompletedSegments() > 0) {
                $tierName = $job->getQualityTierName();
                $mediaManifestUrls[$tierName] = sprintf(
                    '/api/stream/%s/media.m3u8?tier=%s',
                    $job->getPublicId()->toString(),
                    $tierName,
                );

                // Collect audio languages from first job that has them
                if (empty($audioLanguages) && !empty($job->getAudioTrackLanguages())) {
                    $audioLanguages = $job->getAudioTrackLanguages();
                }
            }
        }

        // Build audio groups from collected languages
        $firstJob = $jobs[0] ?? null;
        if ($firstJob !== null) {
            $audioCodec = 'aac';
            $audioCodecRfc6381 = $this->audioCodecToRfc6381($audioCodec);

            foreach ($audioLanguages as $language) {
                $audioGroups[] = [
                    'language' => $language,
                    'name' => $this->languageName($language),
                    'uri' => sprintf('/api/stream/%s/audio/%s/media.m3u8', $firstJob->getPublicId()->toString(), $language),
                    'channels' => '2',
                    'isDefault' => $language === ($audioLanguages[0] ?? 'en'),
                    'groupId'   => $audioCodec,
                    'codec'     => $audioCodecRfc6381,
                ];
            }

            // Build subtitle groups (discovered from probe data)
            $probeData = $firstJob->getProbeData();
            $subtitleLanguages = array_column($probeData['subtitleStreams'] ?? [], 'language');

            foreach ($subtitleLanguages as $language) {
                $subtitleGroups[] = [
                    'language' => $language,
                    'name' => $this->languageName($language),
                    'uri' => sprintf('/api/stream/%s/subtitles/%s/media.m3u8', $firstJob->getPublicId()->toString(), $language),
                    'isDefault' => $language === ($subtitleLanguages[0] ?? 'en'),
                    'groupId' => 'subs',
                ];
            }
        }

        if (empty($mediaManifestUrls)) {
            return $this->manifestGenerator->generateMasterManifest([]);
        }

        return $this->manifestGenerator->generateMasterManifest($mediaManifestUrls, $audioGroups, $subtitleGroups);
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
        $initSegmentUrl = sprintf(
            '/api/stream/%s/init.mp4',
            $job->getPublicId()->toString(),
        );

        return $this->manifestGenerator->generateMediaManifest(
            $tier,
            $initSegmentUrl,
            $job->getSegmentMap(),
        );
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
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        if ($job === null) {
            return null;
        }

        $segmentMap = $job->getSegmentMap();
        $key = (string)$segmentIndex;

        if (!isset($segmentMap[$key])) {
            return null;
        }

        $path = $segmentMap[$key]['path'];
        if (!$this->storage->exists($path)) {
            return null;
        }

        return $path;
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
        if ($path === null || !$this->storage->exists($path)) {
            return null;
        }

        return $path;
    }

    public function getDashManifest(Uuid $videoId): string
    {
        $jobs = $this->jobRepository->findActiveByVideo($videoId);
        $renditions = [];
        $totalDuration = 0.0;

        foreach ($jobs as $job) {
            if ($job->getCompletedSegments() > 0) {
                $tier = QualityTier::fromString($job->getQualityTierName());
                $renditions[$job->getQualityTierName()] = [
                    'public_id'      => $job->getPublicId()->toString(),
                    'quality_tier'   => $tier,
                    'segment_map'    => $job->getSegmentMap(),
                    'total_duration' => array_sum(array_column($job->getSegmentMap(), 'duration')),
                    'audio_codec_rfc6381' => $this->audioCodecToRfc6381('aac'),
                ];
                $totalDuration = max($totalDuration, $renditions[$job->getQualityTierName()]['total_duration']);
            }
        }

        return $this->dashManifestGenerator->generate($renditions, $totalDuration);
    }

    public function getQualityLadderForVideo(Uuid $videoId): array
    {
        $jobs = $this->jobRepository->findActiveByVideo($videoId);
        $tiers = [];

        foreach ($jobs as $job) {
            if ($job->getCompletedSegments() > 0) {
                $tiers[] = QualityTier::fromString($job->getQualityTierName());
            }
        }

        return $this->qualityLadderRenderer->renderAvailableTiers($tiers);
    }

    // --- Audio Delivery ---

    public function getAudioManifest(PublicId $jobPublicId, string $language): string
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        if ($job === null) {
            return $this->manifestGenerator->generateAudioManifest($language, '', []);
        }

        $initSegmentUrl = sprintf(
            '/api/stream/%s/audio/%s/init.mp4',
            $job->getPublicId()->toString(),
            $language,
        );

        $segments = $this->extractAudioSegmentsForLanguage($job->getAudioSegmentMap(), $language);

        return $this->manifestGenerator->generateAudioManifest($language, $initSegmentUrl, $segments);
    }

    public function getAudioInitSegment(PublicId $jobPublicId, string $language): ?string
    {
        $path = $this->getAudioInitSegmentPath($jobPublicId, $language);
        if ($path === null) {
            return null;
        }

        return file_get_contents($path);
    }

    public function getAudioInitSegmentPath(PublicId $jobPublicId, string $language): ?string
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        if ($job === null) {
            return null;
        }

        $path = $this->storage->resolveAudioInitSegmentPath($job->getVideoId(), $language);
        if (!$this->storage->exists($path)) {
            return null;
        }

        return $path;
    }

    public function getAudioSegment(PublicId $jobPublicId, string $language, int $segmentIndex): ?string
    {
        $path = $this->getAudioSegmentPath($jobPublicId, $language, $segmentIndex);
        if ($path === null) {
            return null;
        }

        return file_get_contents($path);
    }

    public function getAudioSegmentPath(PublicId $jobPublicId, string $language, int $segmentIndex): ?string
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        if ($job === null) {
            return null;
        }

        $path = $this->storage->resolveAudioSegmentPath($job->getVideoId(), $language, $segmentIndex);
        if (!$this->storage->exists($path)) {
            return null;
        }

        return $path;
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
     * Extract audio segments for a specific language from the audioSegmentMap.
     *
     * @param array<string, array{path: string, size: int, duration: float}> $audioSegmentMap
     * @return array<int, array{path: string, duration: float}>
     */
    private function extractAudioSegmentsForLanguage(array $audioSegmentMap, string $language): array
    {
        $segments = [];
        $prefix = $language . ':';

        foreach ($audioSegmentMap as $key => $data) {
            if (str_starts_with($key, $prefix)) {
                $index = (int) substr($key, strlen($prefix));
                $segments[$index] = [
                    'path'     => $data['path'],
                    'duration' => $data['duration'],
                ];
            }
        }

        ksort($segments, SORT_NUMERIC);
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
