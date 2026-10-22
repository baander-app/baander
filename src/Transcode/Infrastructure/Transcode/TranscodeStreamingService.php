<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\DTO\TranscodeRenditionSet;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\Service\VideoProcessingRules;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\SubtitleTrackInfo;
use App\Transcode\Infrastructure\DASH\DashManifestGenerator;
use App\Transcode\Infrastructure\HLS\ManifestGenerator;
use App\Transcode\Infrastructure\HLS\QualityLadderRenderer;

/**
 * @phpstan-type GenerationMetadata array{videoCodec: 'h264'|'hevc'|'av1', audioCodec: ?string, audioProfile: array<string, mixed>, segmentDuration: int|float, totalSegments: int, duration: float, startSegment: int, startTime: float}
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

    public function getPlaybackState(Uuid $videoId, array $allowedJobPublicIds): ?array
    {
        $jobs = $this->allowedJobs($videoId, $allowedJobPublicIds);
        if ($jobs === []) {
            return null;
        }
        $primary = $jobs[0];
        $runtime = $this->ownership->find($primary->getId());
        if ($runtime === null) {
            return null;
        }
        $pairs = [];
        $timeline = null;
        foreach ($jobs as $job) {
            $current = $this->ownership->find($job->getId());
            if ($current === null || !$this->samePlayback($runtime, $current)) {
                continue;
            }
            $context = $this->context($job, $current->generation);
            if ($context === null || ($timeline !== null && !$this->sameTimeline($timeline, $context['metadata']))) {
                continue;
            }
            $timeline ??= $context['metadata'];
            $pairs[] = ['jobId' => $job->getPublicId()->toString(), 'generation' => $current->generation];
        }
        return [
            'jobId' => $primary->getPublicId()->toString(),
            'generation' => $runtime->generation,
            'revision' => $runtime->desiredRevision,
            'ready' => $pairs !== [],
            'position' => $runtime->desired->position,
            'status' => $runtime->desired->status,
            'startTime' => $timeline['startTime'] ?? null,
            'duration' => $timeline['duration'] ?? null,
            'renditionSet' => $pairs === [] ? '' : TranscodeRenditionSet::encode($pairs),
        ];
    }

    public function getMasterManifest(Uuid $videoId, array $renditions): string
    {
        $contexts = $this->renditions($videoId, $renditions);
        $urls = $codecs = $subtitles = $bandwidths = [];
        foreach ($contexts ?? [] as $entry) {
            $job = $entry['job'];
            $context = $entry['context'];
            $generation = $context['runtime']->generation;
            $tier = QualityTier::fromString($job->getQualityTierName());
            $urls[$tier->name] = $this->signed('/api/transcode/'.$job->getPublicId().'/media.m3u8', $generation, ['tier' => $tier->name]);
            $codecs[$tier->name] = $this->renditionCodecs($context['metadata'], $tier);
            $bandwidths[$tier->name] = $this->bandwidth($context['metadata'], $tier);
            if ($subtitles === []) {
                $hasDefault = false;
                foreach ($job->getProbeData()['subtitleStreams'] ?? [] as $data) {
                    $track = SubtitleTrackInfo::fromSerialized($data);
                    if (!$track->isText() || $this->subtitlePath($job, $context['storage'], $track->trackKey(), 'full') === null) {
                        continue;
                    }
                    $default = $track->isDefault && !$hasDefault;
                    $hasDefault = $hasDefault || $default;
                    $subtitles[] = ['language' => $track->language, 'name' => $track->getDisplayName().' ('.$track->trackKey().')', 'uri' => $this->signed('/api/transcode/'.$job->getPublicId().'/subtitles/'.$track->trackKey().'/media.m3u8', $generation), 'isDefault' => $default, 'isForced' => $track->isForced, 'groupId' => 'subs'];
                }
            }
        }
        return $this->manifestGenerator->generateMasterManifest($urls, [], $subtitles, $codecs, $bandwidths);
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

    public function getDashManifest(Uuid $videoId, array $renditions): string
    {
        $contexts = $this->renditions($videoId, $renditions);
        $representations = []; $duration = 0.0;
        foreach ($contexts ?? [] as $entry) {
            $job = $entry['job']; $context = $entry['context'];
            $generation = $context['runtime']->generation;
            $tier = QualityTier::fromString($job->getQualityTierName());
            $metadata = $context['metadata'];
            $representations[$tier->name] = [
                'public_id' => $job->getPublicId()->toString(), 'quality_tier' => $tier,
                'init_url' => $this->signed('/api/transcode/'.$job->getPublicId().'/init', $generation),
                'segment_map' => $this->segments($job, $metadata, $generation),
                'total_duration' => $metadata['duration'] - $metadata['startTime'],
                'video_codec_rfc6381' => $this->renditionCodecs($metadata, $tier),
                'bandwidth' => $this->bandwidth($metadata, $tier),
                'audio_codec_rfc6381' => $metadata['audioCodec'] === null ? '' : $this->audioCodecToRfc6381($metadata['audioCodec']),
            ];
            $duration = max($duration, $metadata['duration'] - $metadata['startTime']);
        }
        return $this->dashManifestGenerator->generate($representations, [], $duration);
    }

    public function getQualityLadderForVideo(Uuid $videoId, array $allowedJobPublicIds): array
    {
        $tiers = [];
        foreach ($this->allowedJobs($videoId, $allowedJobPublicIds) as $job) {
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
        if ($context !== null && preg_match('/^s[0-9]+$/D', $language) === 1
            && $this->subtitlePath($job, $context['storage'], $language, 'full') !== null) {
            $segments[] = ['segmentName' => 'full', 'duration' => $context['metadata']['duration'] - $context['metadata']['startTime'], 'url' => $this->signed('/api/transcode/'.$jobPublicId.'/subtitles/'.$language.'/full.vtt', $generation)];
        }
        if ($context !== null) {
            $runtime = $this->ownership->find($job->getId());
            if ($runtime === null || !$this->readable($runtime, $generation, $context['directory'])) {
                $segments = [];
            }
        }
        return $this->manifestGenerator->generateSubtitleManifest($language, $segments, $segments[0]['duration'] ?? 6.0);
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
        if (preg_match('/^s[0-9]+$/D', $language) !== 1 || $segmentName !== 'full') {
            return null;
        }
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

    /** @return array{runtime: TranscodeRuntimeSnapshot, storage: TranscodeStoragePortInterface, directory: string, metadata: GenerationMetadata}|null */
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
            $storage = $this->storage->forGeneration($job->getVideoId(), $tier, $generation, $job->getId());
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
                || !is_string($metadata['videoEncoder'] ?? null) || !array_key_exists('audioCodec', $metadata) || ($metadata['audioCodec'] !== null && !is_string($metadata['audioCodec']))
                || !is_array($metadata['audioProfile'] ?? null) || json_encode($metadata['audioProfile'], JSON_THROW_ON_ERROR) !== json_encode($runtime->desired->audioProfile->jsonSerialize(), JSON_THROW_ON_ERROR)
                || (!is_int($metadata['segmentDuration'] ?? null) && !is_float($metadata['segmentDuration'] ?? null)) || $metadata['segmentDuration'] <= 0
                || !is_int($metadata['totalSegments'] ?? null) || $metadata['totalSegments'] < 1
                || !is_int($metadata['startSegment'] ?? null) || $metadata['startSegment'] < 0 || $metadata['startSegment'] >= $metadata['totalSegments']
                || (!is_int($metadata['startTime'] ?? null) && !is_float($metadata['startTime'] ?? null)) || $metadata['startTime'] < 0
                || abs($metadata['startTime'] - $metadata['startSegment'] * $metadata['segmentDuration']) > 0.001
                || (!is_int($metadata['duration'] ?? null) && !is_float($metadata['duration'] ?? null)) || $metadata['duration'] <= 0) {
                return null;
            }
            return ['runtime' => $runtime, 'storage' => $storage, 'directory' => $directory, 'metadata' => [
                'videoCodec' => $metadata['videoCodec'],
                'audioCodec' => $metadata['audioCodec'],
                'audioProfile' => $metadata['audioProfile'],
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

    /**
     * @param list<string> $allowedJobPublicIds
     * @return list<TranscodeJob>
     */
    private function allowedJobs(Uuid $videoId, array $allowedJobPublicIds): array
    {
        if ($allowedJobPublicIds === [] || count($allowedJobPublicIds) > 3 || count(array_unique($allowedJobPublicIds)) !== count($allowedJobPublicIds)) {
            return [];
        }
        $jobs = [];
        $owner = null;
        foreach ($allowedJobPublicIds as $publicId) {
            $job = $this->jobRepository->findByPublicId(PublicId::fromString($publicId));
            if ($job === null || !$job->getVideoId()->equals($videoId) || $job->getOwnerUserId() === null) {
                return [];
            }
            $owner ??= $job->getOwnerUserId();
            if (!$job->getOwnerUserId()->equals($owner)) {
                return [];
            }
            $jobs[] = $job;
        }
        usort($jobs, static fn (TranscodeJob $left, TranscodeJob $right): int => QualityTier::fromString($left->getQualityTierName())->height <=> QualityTier::fromString($right->getQualityTierName())->height);
        return $jobs;
    }

    /**
     * @param list<array{jobId: string, generation: int}> $pairs
     * @return list<array{job: TranscodeJob, context: array{runtime: TranscodeRuntimeSnapshot, storage: TranscodeStoragePortInterface, directory: string, metadata: GenerationMetadata}}>|null
     */
    private function renditions(Uuid $videoId, array $pairs): ?array
    {
        TranscodeRenditionSet::encode($pairs);
        $jobs = $this->allowedJobs($videoId, array_column($pairs, 'jobId'));
        if (count($jobs) !== count($pairs)) {
            return null;
        }
        $generations = array_column($pairs, 'generation', 'jobId');
        $entries = [];
        $first = null;
        foreach ($jobs as $job) {
            $context = $this->context($job, $generations[$job->getPublicId()->toString()]);
            if ($context === null || ($first !== null && (!$this->samePlayback($first['runtime'], $context['runtime']) || !$this->sameTimeline($first['metadata'], $context['metadata'])))) {
                return null;
            }
            $first ??= $context;
            $entries[] = ['job' => $job, 'context' => $context];
        }
        return $entries;
    }

    private function samePlayback(TranscodeRuntimeSnapshot $left, TranscodeRuntimeSnapshot $right): bool
    {
        return $left->desired->position === $right->desired->position && $left->desired->status === $right->desired->status
            && $left->desired->audioProfile->jsonSerialize() === $right->desired->audioProfile->jsonSerialize();
    }

    /**
     * @param GenerationMetadata $left
     * @param GenerationMetadata $right
     */
    private function sameTimeline(array $left, array $right): bool
    {
        return $left['startTime'] === $right['startTime'] && $left['duration'] === $right['duration']
            && $left['segmentDuration'] === $right['segmentDuration'] && $left['totalSegments'] === $right['totalSegments'];
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
    private function renditionCodecs(array $metadata, QualityTier $tier): string
    {
        return $this->videoCodec($metadata, $tier) . ($metadata['audioCodec'] === null ? '' : ',' . $this->audioCodecToRfc6381($metadata['audioCodec']));
    }

    /** @param GenerationMetadata $metadata */
    private function bandwidth(array $metadata, QualityTier $tier): int
    {
        return (int) ceil(($tier->maxBitrate + ($metadata['audioCodec'] === null ? 0 : (int) ($metadata['audioProfile']['bitrate'] ?? 0))) * 1.05);
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

}
