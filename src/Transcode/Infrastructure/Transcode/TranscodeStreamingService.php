<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\Service\VideoProcessingRules;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\DASH\DashManifestGenerator;
use App\Transcode\Infrastructure\HLS\ManifestGenerator;
use App\Transcode\Infrastructure\HLS\QualityLadderRenderer;

/**
 * @phpstan-type GenerationMetadata array{videoCodec: 'h264'|'hevc'|'av1', audioCodec: string, segmentDuration: int|float, totalSegments: int, duration: float, startSegment: int, startTime: float}
 */
final class TranscodeStreamingService implements TranscodeStreamingPortInterface
{
    public function __construct(
        private readonly TranscodeJobRepositoryInterface $jobRepository,
        private readonly TranscodeStoragePortInterface $storage,
        private readonly ManifestGenerator $manifestGenerator,
        private readonly DashManifestGenerator $dashManifestGenerator,
        private readonly QualityLadderRenderer $qualityLadderRenderer,
        private readonly StreamAuthPortInterface $streamAuth,
        private readonly TranscodeExecutionOwnershipPort $ownership,
    ) {}

    public function getPlaybackState(Uuid $videoId, ?string $jobPublicId = null): ?array
    {
        $pending = null;
        foreach ($this->jobRepository->findActiveByVideo($videoId) as $job) {
            if ($jobPublicId !== null && $job->getPublicId()->toString() !== $jobPublicId) {
                continue;
            }
            $runtime = $this->ownership->find($job->getId());
            if ($runtime === null) {
                continue;
            }
            $context = $this->context($job, $runtime->generation);
            $ready = $context !== null;
            $state = [
                'jobId' => $job->getPublicId()->toString(),
                'generation' => $runtime->generation,
                'revision' => $runtime->desiredRevision,
                'ready' => $ready,
                'position' => $runtime->desired->position,
                'status' => $runtime->desired->status,
                'startTime' => $context['metadata']['startTime'] ?? null,
                'duration' => $context['metadata']['duration'] ?? null,
            ];
            if ($ready) {
                return $state;
            }
            $pending ??= $state;
        }
        return $pending;
    }

    public function getMasterManifest(Uuid $videoId, int $generation, ?string $jobPublicId = null): string
    {
        $urls = $codecs = $subtitles = [];
        foreach ($this->jobRepository->findActiveByVideo($videoId) as $job) {
            if ($jobPublicId !== null && $job->getPublicId()->toString() !== $jobPublicId) {
                continue;
            }
            $context = $this->context($job, $generation);
            if ($context === null) {
                continue;
            }
            $tier = QualityTier::fromString($job->getQualityTierName());
            $urls[$tier->name] = $this->signed('/api/transcode/'.$job->getPublicId().'/media.m3u8', $generation, ['tier' => $tier->name]);
            $codecs[$tier->name] = $this->videoCodec($context['metadata'], $tier).','.$this->audioCodecToRfc6381($context['metadata']['audioCodec']);
            if ($subtitles === []) {
                foreach (array_column($job->getProbeData()['subtitleStreams'] ?? [], 'language') as $language) {
                    $subtitles[] = ['language' => $language, 'name' => $this->languageName($language), 'uri' => $this->signed('/api/transcode/'.$job->getPublicId().'/subtitles/'.rawurlencode($language).'/media.m3u8', $generation), 'isDefault' => $subtitles === [], 'groupId' => 'subs'];
                }
            }
        }
        return $this->manifestGenerator->generateMasterManifest($urls, [], $subtitles, $codecs);
    }

    public function getMediaManifest(PublicId $jobPublicId, string $audioProfileName, int $generation): string
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        $context = $job === null ? null : $this->context($job, $generation);
        if ($context === null) {
            return $this->manifestGenerator->generateMediaManifest(QualityTier::p720(), '', []);
        }
        return $this->manifestGenerator->generateMediaManifest(
            QualityTier::fromString($job->getQualityTierName()),
            $this->signed('/api/transcode/'.$jobPublicId.'/init', $generation),
            $this->segments($job, $context['metadata'], $generation),
            $context['metadata']['segmentDuration'],
        );
    }

    /**
     * @param GenerationMetadata $metadata
     * @return array<int, array{url: string, duration: float}>
     */
    private function segments(TranscodeJob $job, array $metadata, int $generation): array
    {
        $segments = [];
        for ($index = $metadata['startSegment']; $index < $metadata['totalSegments']; ++$index) {
            $segments[$index] = [
                'url' => $this->signed('/api/transcode/'.$job->getPublicId().'/segment', $generation, ['index' => $index]),
                'duration' => max(0.0, min($metadata['segmentDuration'], $metadata['duration'] - $index * $metadata['segmentDuration'])),
            ];
        }
        return $segments;
    }

    public function getSegment(PublicId $jobPublicId, int $segmentIndex, int $generation): ?string
    {
        $path = $this->getSegmentPath($jobPublicId, $segmentIndex, $generation);
        return $path === null ? null : $this->read($path);
    }

    public function getSegmentPath(PublicId $jobPublicId, int $segmentIndex, int $generation): ?string
    {
        return $this->resolveVideoSegmentAvailability($jobPublicId, $segmentIndex, $generation)['path'] ?? null;
    }

    public function resolveVideoSegmentAvailability(PublicId $jobPublicId, int $segmentIndex, int $generation): ?array
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        $context = $job === null ? null : $this->context($job, $generation);
        if ($context === null || $segmentIndex < $context['metadata']['startSegment'] || $segmentIndex >= $context['metadata']['totalSegments']) {
            return null;
        }
        try {
            return [
                'jobId' => $job->getId(),
                'tierKey' => $job->getQualityTierName().'/generation-'.$generation,
                'path' => $context['storage']->resolveSegmentPath($job->getVideoId(), QualityTier::fromString($job->getQualityTierName()), $segmentIndex),
            ];
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function getInitSegment(PublicId $jobPublicId, int $generation): ?string
    {
        $path = $this->getInitSegmentPath($jobPublicId, $generation);
        return $path === null ? null : $this->read($path);
    }

    public function getInitSegmentPath(PublicId $jobPublicId, int $generation): ?string
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        $context = $job === null ? null : $this->context($job, $generation);
        if ($context === null) {
            return null;
        }
        try {
            return $context['storage']->resolveInitSegmentPath($job->getVideoId(), QualityTier::fromString($job->getQualityTierName()));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function getDashManifest(Uuid $videoId, int $generation, ?string $jobPublicId = null): string
    {
        $renditions = []; $duration = 0.0;
        foreach ($this->jobRepository->findActiveByVideo($videoId) as $job) {
            if ($jobPublicId !== null && $job->getPublicId()->toString() !== $jobPublicId) {
                continue;
            }
            $context = $this->context($job, $generation);
            if ($context === null) {
                continue;
            }
            $tier = QualityTier::fromString($job->getQualityTierName());
            $metadata = $context['metadata'];
            $renditions[$tier->name] = [
                'public_id' => $job->getPublicId()->toString(), 'quality_tier' => $tier,
                'init_url' => $this->signed('/api/transcode/'.$job->getPublicId().'/init', $generation),
                'segment_map' => $this->segments($job, $metadata, $generation),
                'total_duration' => $metadata['duration'],
                'video_codec_rfc6381' => $this->videoCodec($metadata, $tier).','.$this->audioCodecToRfc6381($metadata['audioCodec']),
                'audio_codec_rfc6381' => $this->audioCodecToRfc6381($metadata['audioCodec']),
            ];
            $duration = max($duration, $metadata['duration']);
        }
        return $this->dashManifestGenerator->generate($renditions, [], $duration);
    }

    public function getQualityLadderForVideo(Uuid $videoId): array
    {
        $tiers = [];
        foreach ($this->jobRepository->findActiveByVideo($videoId) as $job) {
            $runtime = $this->ownership->find($job->getId());
            $context = $runtime === null ? null : $this->context($job, $runtime->generation);
            if ($context !== null) {
                $tier = QualityTier::fromString($job->getQualityTierName());
                $entry = $this->qualityLadderRenderer->renderAvailableTiers([$tier])[0];
                $entry['codec'] = $this->videoCodec($context['metadata'], $tier);
                $tiers[] = $entry;
            }
        }
        return $tiers;
    }

    public function getSubtitleManifest(PublicId $jobPublicId, string $language, int $generation): string
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        $context = $job === null ? null : $this->context($job, $generation);
        $segments = [];
        if ($context !== null) {
            try {
                $directory = $context['storage']->resolveSubtitleDirectory($job->getVideoId(), $language);
            } catch (\InvalidArgumentException) {
                return $this->manifestGenerator->generateSubtitleManifest($language, []);
            }
            $files = glob($directory.'/*.vtt') ?: []; sort($files);
            foreach ($files as $file) {
                $name = basename($file, '.vtt');
                if ($this->subtitlePath($job, $context['storage'], $language, $name) !== null) {
                    $segments[] = ['segmentName' => $name, 'duration' => $context['metadata']['segmentDuration'], 'url' => $this->signed('/api/transcode/'.$jobPublicId.'/subtitles/'.rawurlencode($language).'/'.rawurlencode($name).'.vtt', $generation)];
                }
            }
        }
        if ($context !== null) {
            $runtime = $this->ownership->find($job->getId());
            if ($runtime === null || !$this->readable($runtime, $generation, $context['directory'])) {
                $segments = [];
            }
        }
        return $this->manifestGenerator->generateSubtitleManifest($language, $segments);
    }

    public function getSubtitleSegment(PublicId $jobPublicId, string $language, string $segmentName, int $generation): ?string
    {
        $path = $this->getSubtitleSegmentPath($jobPublicId, $language, $segmentName, $generation);
        return $path === null ? null : $this->read($path);
    }

    public function getSubtitleSegmentPath(PublicId $jobPublicId, string $language, string $segmentName, int $generation): ?string
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        $context = $job === null ? null : $this->context($job, $generation);
        if ($context === null) {
            return null;
        }
        return $this->subtitlePath($job, $context['storage'], $language, $segmentName);
    }

    private function subtitlePath(TranscodeJob $job, TranscodeStoragePortInterface $storage, string $language, string $segmentName): ?string
    {
        try {
            $path = $storage->resolveSubtitleSegmentPath($job->getVideoId(), $language, $segmentName);
            return $storage->exists($path) && is_file($path) ? $path : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function readable(TranscodeRuntimeSnapshot $runtime, int $generation, string $directory): bool
    {
        return $runtime->generation === $generation && $runtime->outputDirectory === $directory
            && in_array($runtime->outcome, ['ready', 'complete'], true)
            && ($runtime->outcome === 'complete' || $runtime->ownershipLive);
    }

    /** @return array{storage: TranscodeStoragePortInterface, directory: string, metadata: GenerationMetadata}|null */
    private function context(TranscodeJob $job, int $generation): ?array
    {
        if ($generation < 1) {
            return null;
        }
        $runtime = $this->ownership->find($job->getId());
        if ($runtime === null) {
            return null;
        }
        try {
            $tier = QualityTier::fromString($job->getQualityTierName());
            $storage = $this->storage->forGeneration($job->getVideoId(), $tier, $generation);
            $directory = $storage->resolveJobDirectory($job->getVideoId(), $tier);
            if (!$this->readable($runtime, $generation, $directory)) {
                return null;
            }
            $path = $directory.'/generation.json';
            if (!$storage->exists($path) || !is_file($path) || is_link($path)) {
                return null;
            }
            $metadata = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($metadata) || ($metadata['schemaVersion'] ?? null) !== 1 || ($metadata['generation'] ?? null) !== $generation
                || !in_array($metadata['videoCodec'] ?? null, ['h264', 'hevc', 'av1'], true)
                || !is_string($metadata['videoEncoder'] ?? null) || !is_string($metadata['audioCodec'] ?? null)
                || !is_array($metadata['audioProfile'] ?? null)
                || (!is_int($metadata['segmentDuration'] ?? null) && !is_float($metadata['segmentDuration'] ?? null)) || $metadata['segmentDuration'] <= 0
                || !is_int($metadata['totalSegments'] ?? null) || $metadata['totalSegments'] < 1
                || !is_int($metadata['startSegment'] ?? null) || $metadata['startSegment'] < 0 || $metadata['startSegment'] >= $metadata['totalSegments']
                || (!is_int($metadata['startTime'] ?? null) && !is_float($metadata['startTime'] ?? null)) || $metadata['startTime'] < 0
                || abs($metadata['startTime'] - $metadata['startSegment'] * $metadata['segmentDuration']) > 0.001
                || (!is_int($metadata['duration'] ?? null) && !is_float($metadata['duration'] ?? null)) || $metadata['duration'] <= 0) {
                return null;
            }
            return ['storage' => $storage, 'directory' => $directory, 'metadata' => [
                'videoCodec' => $metadata['videoCodec'],
                'audioCodec' => $metadata['audioCodec'],
                'segmentDuration' => $metadata['segmentDuration'],
                'totalSegments' => $metadata['totalSegments'],
                'duration' => (float) $metadata['duration'],
                'startSegment' => $metadata['startSegment'],
                'startTime' => (float) $metadata['startTime'],
            ]];
        } catch (\InvalidArgumentException|\JsonException) {
            return null;
        }
    }

    /** @param array<string, int|string> $query */
    private function signed(string $path, int $generation, array $query = []): string
    {
        return $this->streamAuth->signUrl($path.'?'.http_build_query($query + ['generation' => $generation]))['url'];
    }

    private function read(string $path): ?string
    {
        $contents = @file_get_contents($path);
        return $contents === false ? null : $contents;
    }

    /** @param GenerationMetadata $metadata */
    private function videoCodec(array $metadata, QualityTier $tier): string
    {
        return VideoProcessingRules::codecRfc6381(match ($metadata['videoCodec']) { 'h264' => 'libx264', 'hevc' => 'libx265', 'av1' => 'libsvtav1' }, $tier->height);
    }

    private function audioCodecToRfc6381(string $codec): string
    {
        return match ($codec) {
            'heaac', 'he-aac', 'heaacv1' => 'mp4a.40.5',
            'heaacv2', 'he-aacv2' => 'mp4a.40.29',
            'opus' => 'Opus', default => 'mp4a.40.2',
        };
    }

    private function languageName(string $code): string
    {
        return ['en' => 'English', 'es' => 'Español', 'fr' => 'Français', 'de' => 'Deutsch', 'it' => 'Italiano', 'pt' => 'Português', 'ja' => '日本語', 'ko' => '한국어', 'zh' => '中文', 'ru' => 'Русский', 'ar' => 'العربية', 'hi' => 'हिन्दी'][$code] ?? $code;
    }
}
