<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\DTO\TranscodeRenditionSet;
use App\Transcode\Application\Port\StreamAuthPortInterface;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\SubtitleTrackInfo;
use App\Transcode\Domain\ValueObject\VideoProbeResult;
use App\Transcode\Infrastructure\DASH\DashManifestGenerator;
use App\Transcode\Infrastructure\HLS\ManifestGenerator;
use App\Transcode\Infrastructure\HLS\QualityLadderRenderer;
use App\Transcode\Infrastructure\Packaging\PublishedPresentation;
use App\Transcode\Infrastructure\Packaging\PublishedPresentationReader;
use App\Transcode\Infrastructure\Packaging\PublishedTrack;

/**
 * @phpstan-type GenerationMetadata array{audioCodec: ?string, audioProfile: array<string, mixed>, duration: float, startTime: float, nativeAvailabilityStartTime: ?string}
 * @phpstan-type Context array{runtime: TranscodeRuntimeSnapshot, storage: TranscodeStoragePortInterface, directory: string, metadata: GenerationMetadata, presentation: PublishedPresentation}
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
        private readonly PublishedPresentationReader $presentationReader,
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
        $canonical = $this->context($primary, $runtime->generation);
        $pairs = [];
        if ($canonical !== null && $this->coversCursor($canonical)) {
            foreach ($jobs as $job) {
                $current = $this->ownership->find($job->getId());
                $context = $current === null ? null : $this->context($job, $current->generation);
                if ($context !== null && $this->compatible($canonical, $context) && $this->coversCursor($context) && $this->canMerge($canonical, $context)) {
                    $pairs[] = ['jobId' => $job->getPublicId()->toString(), 'generation' => $context['runtime']->generation];
                }
            }
        }
        // The selected session remains primary while other renditions warm up.
        if (!in_array($primary->getPublicId()->toString(), array_column($pairs, 'jobId'), true)) {
            $pairs = [];
        }
        $runtime = $canonical['runtime'] ?? $runtime;
        return [
            'jobId' => $primary->getPublicId()->toString(), 'generation' => $runtime->generation,
            'revision' => $runtime->desiredRevision, 'ready' => $pairs !== [],
            'position' => $runtime->desired->position, 'status' => $runtime->desired->status,
            'startTime' => $canonical['metadata']['startTime'] ?? null,
            'duration' => $canonical['metadata']['duration'] ?? null,
            'renditionSet' => $pairs === [] ? '' : TranscodeRenditionSet::encode($pairs),
        ];
    }

    public function getMasterManifest(Uuid $videoId, array $renditions, int $expiresAt): string
    {
        $contexts = $this->renditions($videoId, $renditions);
        if ($contexts === null || $contexts === []) {
            return $this->manifestGenerator->generateMasterManifest([]);
        }
        $primary = $contexts[0];
        $primaryJob = $primary['job'];
        $primaryContext = $primary['context'];
        $audio = $primaryContext['presentation']->track(MediaTrack::Audio);
        $audioGroups = $audio === null ? [] : [[
            'language' => VideoProbeResult::fromSerialized($primaryJob->getProbeData())->selectedAudioStream()->language ?? 'und',
            'name' => 'Audio', 'groupId' => 'audio', 'isDefault' => true,
            'channels' => (string) $primaryContext['runtime']->desired->audioProfile->channelCount,
            'uri' => $this->trackUrl($primaryJob, MediaTrack::Audio, 'media.m3u8', $primaryContext['runtime']->generation, $expiresAt),
        ]];
        $urls = $codecs = $bandwidths = [];
        foreach ($contexts as $entry) {
            $job = $entry['job']; $context = $entry['context'];
            $tier = QualityTier::fromString($job->getQualityTierName());
            $video = $context['presentation']->track(MediaTrack::Video);
            if ($video === null) {
                return $this->manifestGenerator->generateMasterManifest([]);
            }
            $urls[$tier->name] = $this->trackUrl($job, MediaTrack::Video, 'media.m3u8', $context['runtime']->generation, $expiresAt, ['tier' => $tier->name]);
            $codecs[$tier->name] = $video->codec . ($audio === null ? '' : ',' . $audio->codec);
            $bandwidths[$tier->name] = $this->bandwidth($context, $tier, $audio !== null);
        }
        return $this->manifestGenerator->generateMasterManifest($urls, $audioGroups, $this->subtitleGroups($primaryJob, $primaryContext, $expiresAt), $codecs, $bandwidths);
    }

    public function getMediaManifest(PublicId $jobPublicId, MediaTrack $track, int $generation, int $expiresAt): string
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        $context = $job === null ? null : $this->context($job, $generation);
        $published = $context === null ? null : $context['presentation']->track($track);
        if ($published === null) {
            return $this->manifestGenerator->generateMediaManifest('', [], false);
        }
        $segments = [];
        foreach ($published->segments as $segment) {
            $segments[$segment->ordinal] = ['duration' => $segment->duration / $published->timescale,
                'url' => $this->trackUrl($job, $track, 'segment.m4s', $generation, $expiresAt, ['index' => $segment->ordinal])];
        }
        return $this->manifestGenerator->generateMediaManifest(
            $this->trackUrl($job, $track, 'init.mp4', $generation, $expiresAt), $segments, $context['presentation']->complete,
        );
    }

    public function getSegment(PublicId $jobPublicId, MediaTrack $track, int $segmentIndex, int $generation): ?string
    {
        $path = $this->getSegmentPath($jobPublicId, $track, $segmentIndex, $generation);
        return $path === null ? null : $this->read($path);
    }

    public function getSegmentPath(PublicId $jobPublicId, MediaTrack $track, int $segmentIndex, int $generation): ?string
    {
        return $this->resolveSegmentAvailability($jobPublicId, $track, $segmentIndex, $generation)['path'] ?? null;
    }

    public function resolveSegmentAvailability(PublicId $jobPublicId, MediaTrack $track, int $segmentIndex, int $generation): ?array
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        $context = $job === null ? null : $this->context($job, $generation);
        $published = $context === null ? null : $context['presentation']->track($track);
        $segment = $published?->segments[$segmentIndex] ?? null;
        if ($segment === null || $segment->ordinal !== $segmentIndex) {
            return null;
        }
        return ['jobId' => $job->getId(), 'tierKey' => $job->getQualityTierName().'/generation-'.$generation.'/'.$track->value,
            'path' => $context['directory'].'/'.$segment->file];
    }

    public function getInitSegment(PublicId $jobPublicId, MediaTrack $track, int $generation): ?string
    {
        $path = $this->getInitSegmentPath($jobPublicId, $track, $generation);
        return $path === null ? null : $this->read($path);
    }

    public function getInitSegmentPath(PublicId $jobPublicId, MediaTrack $track, int $generation): ?string
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        $context = $job === null ? null : $this->context($job, $generation);
        $published = $context === null ? null : $context['presentation']->track($track);
        return $published === null ? null : $context['directory'].'/'.$published->initFile;
    }

    public function getDashManifest(Uuid $videoId, array $renditions, int $expiresAt): string
    {
        $contexts = $this->renditions($videoId, $renditions);
        if ($contexts === null || $contexts === []) {
            return $this->dashManifestGenerator->generate([]);
        }
        $primary = $contexts[0];
        $representations = [];
        foreach ($contexts as $entry) {
            $job = $entry['job']; $context = $entry['context'];
            $track = $context['presentation']->track(MediaTrack::Video);
            if ($track === null) {
                return $this->dashManifestGenerator->generate([]);
            }
            $tier = QualityTier::fromString($job->getQualityTierName());
            $representations[$tier->name] = $this->dashTrack($job, $context, $track, $expiresAt) + [
                'quality_tier' => $tier, 'bandwidth' => (int) ceil($tier->maxBitrate * 1.05),
            ];
        }
        $audioTrack = $primary['context']['presentation']->track(MediaTrack::Audio);
        $audio = $audioTrack === null ? null : $this->dashTrack($primary['job'], $primary['context'], $audioTrack, $expiresAt) + [
            'language' => VideoProbeResult::fromSerialized($primary['job']->getProbeData())->selectedAudioStream()->language ?? 'und',
            'bandwidth' => $primary['context']['runtime']->desired->audioProfile->bitrate,
            'sample_rate' => $primary['context']['runtime']->desired->audioProfile->sampleRate,
            'channels' => $primary['context']['runtime']->desired->audioProfile->channelCount,
        ];
        return $this->dashManifestGenerator->generate($representations, $audio, $this->mergedPresentation($contexts));
    }

    /** @param Context $context
     * @return array{track: PublishedTrack, init_url: string, segment_urls: array<int, string>}
     */
    private function dashTrack(TranscodeJob $job, array $context, PublishedTrack $track, int $expiresAt): array
    {
        $generation = $context['runtime']->generation;
        $urls = [];
        foreach ($track->segments as $segment) {
            $urls[$segment->ordinal] = $this->trackUrl($job, $track->kind, 'segment.m4s', $generation, $expiresAt, ['index' => $segment->ordinal]);
        }
        return ['track' => $track, 'init_url' => $this->trackUrl($job, $track->kind, 'init.mp4', $generation, $expiresAt), 'segment_urls' => $urls];
    }

    public function getQualityLadderForVideo(Uuid $videoId, array $allowedJobPublicIds): array
    {
        $tiers = [];
        foreach ($this->allowedJobs($videoId, $allowedJobPublicIds) as $job) {
            $runtime = $this->ownership->find($job->getId());
            $context = $runtime === null ? null : $this->context($job, $runtime->generation);
            $video = $context === null ? null : $context['presentation']->track(MediaTrack::Video);
            if ($video !== null) {
                $tier = QualityTier::fromString($job->getQualityTierName());
                $entry = $this->qualityLadderRenderer->renderAvailableTiers([$tier])[0];
                $entry['codec'] = $video->codec;
                $tiers[] = $entry;
            }
        }
        return $tiers;
    }

    public function getSubtitleManifest(PublicId $jobPublicId, string $language, int $generation, int $expiresAt): string
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        $context = $job === null ? null : $this->context($job, $generation);
        $segments = [];
        if ($context !== null && $this->subtitlePath($job, $context['storage'], $language, 'full') !== null) {
            $segments[] = ['segmentName' => 'full', 'duration' => $context['metadata']['duration'] - $context['metadata']['startTime'],
                'url' => $this->signed('/api/transcode/'.$jobPublicId.'/subtitles/'.$language.'/full.vtt', $generation, $expiresAt)];
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
        return $context === null ? null : $this->subtitlePath($job, $context['storage'], $language, $segmentName);
    }

    /** @param Context $context
     * @return list<array{language: string, name: string, uri: string, isDefault: bool, isForced: bool, groupId: string}>
     */
    private function subtitleGroups(TranscodeJob $job, array $context, int $expiresAt): array
    {
        $groups = []; $hasDefault = false;
        foreach ($job->getProbeData()['subtitleStreams'] ?? [] as $data) {
            $track = SubtitleTrackInfo::fromSerialized($data);
            if (!$track->isText() || $this->subtitlePath($job, $context['storage'], $track->trackKey(), 'full') === null) {
                continue;
            }
            $default = $track->isDefault && !$hasDefault;
            $hasDefault = $hasDefault || $default;
            $groups[] = ['language' => $track->language, 'name' => $track->getDisplayName().' ('.$track->trackKey().')',
                'uri' => $this->signed('/api/transcode/'.$job->getPublicId().'/subtitles/'.$track->trackKey().'/media.m3u8', $context['runtime']->generation, $expiresAt),
                'isDefault' => $default, 'isForced' => $track->isForced, 'groupId' => 'subs'];
        }
        return $groups;
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

    /** @return Context|null */
    private function context(TranscodeJob $job, int $generation): ?array
    {
        $runtime = $generation < 1 ? null : $this->ownership->find($job->getId());
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
            if (!is_array($metadata) || ($metadata['schemaVersion'] ?? null) !== 2 || ($metadata['generation'] ?? null) !== $generation
                || !in_array($metadata['videoCodec'] ?? null, ['h264', 'hevc', 'av1'], true)
                || !is_string($metadata['videoEncoder'] ?? null) || !array_key_exists('audioCodec', $metadata) || ($metadata['audioCodec'] !== null && !is_string($metadata['audioCodec']))
                || !is_array($metadata['audioProfile'] ?? null) || $metadata['audioProfile'] !== json_decode(json_encode($runtime->desired->audioProfile, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR)
                || (!is_int($metadata['startTime'] ?? null) && !is_float($metadata['startTime'] ?? null)) || !is_finite((float) $metadata['startTime']) || $metadata['startTime'] < 0
                || (!is_int($metadata['duration'] ?? null) && !is_float($metadata['duration'] ?? null)) || !is_finite((float) $metadata['duration']) || $metadata['duration'] <= $metadata['startTime']) {
                return null;
            }
            $presentation = $this->presentationReader->read($directory);
            if ($presentation === null || $presentation->track(MediaTrack::Video) === null
                || (($metadata['audioCodec'] !== null) !== ($presentation->track(MediaTrack::Audio) !== null))) {
                return null;
            }
            $latest = $this->ownership->find($job->getId());
            // Position telemetry advances revisions without replacing the encoded media.
            if ($latest === null || !$this->readable($latest, $generation, $directory)
                || $latest->desired->audioProfile->jsonSerialize() !== $runtime->desired->audioProfile->jsonSerialize()) {
                return null;
            }
            return ['runtime' => $latest, 'storage' => $storage, 'directory' => $directory, 'presentation' => $presentation,
                'metadata' => ['audioCodec' => $metadata['audioCodec'], 'audioProfile' => $metadata['audioProfile'],
                    'duration' => (float) $metadata['duration'], 'startTime' => (float) $metadata['startTime'],
                    'nativeAvailabilityStartTime' => is_string($metadata['nativeAvailabilityStartTime'] ?? null) ? $metadata['nativeAvailabilityStartTime'] : null]];
        } catch (\InvalidArgumentException|\JsonException) {
            return null;
        }
    }

    /** @param list<string> $allowedJobPublicIds
     * @return list<TranscodeJob>
     */
    private function allowedJobs(Uuid $videoId, array $allowedJobPublicIds): array
    {
        if ($allowedJobPublicIds === [] || count($allowedJobPublicIds) > 3 || count(array_unique($allowedJobPublicIds)) !== count($allowedJobPublicIds)) {
            return [];
        }
        $jobs = []; $owner = null;
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

    /** @param list<array{jobId: string, generation: int}> $pairs
     * @return list<array{job: TranscodeJob, context: Context}>|null
     */
    private function renditions(Uuid $videoId, array $pairs): ?array
    {
        TranscodeRenditionSet::encode($pairs);
        $jobs = $this->allowedJobs($videoId, array_column($pairs, 'jobId'));
        if (count($jobs) !== count($pairs)) {
            return null;
        }
        $generations = array_column($pairs, 'generation', 'jobId');
        $entries = []; $first = null;
        foreach ($jobs as $job) {
            $context = $this->context($job, $generations[$job->getPublicId()->toString()]);
            if ($context === null || ($first !== null && (!$this->compatible($first, $context) || !$this->canMerge($first, $context)))) {
                return null;
            }
            $first ??= $context;
            $entries[] = ['job' => $job, 'context' => $context];
        }
        return $entries;
    }

    /** @param Context $context */
    private function coversCursor(array $context): bool
    {
        $position = $context['runtime']->desired->position - $context['metadata']['startTime'];
        return $context['presentation']->covers($position, $context['metadata']['audioCodec'] !== null, $context['metadata']['duration'] - $context['metadata']['startTime']);
    }

    /**
     * @param Context $left
     * @param Context $right
     */
    private function compatible(array $left, array $right): bool
    {
        return $left['runtime']->desired->position === $right['runtime']->desired->position
            && $left['runtime']->desired->status === $right['runtime']->desired->status
            && $left['metadata']['startTime'] === $right['metadata']['startTime']
            && $left['metadata']['duration'] === $right['metadata']['duration']
            && $left['metadata']['audioCodec'] === $right['metadata']['audioCodec']
            && $left['metadata']['audioProfile'] === $right['metadata']['audioProfile']
            && ($left['presentation']->periodAttributes['start'] ?? 'PT0S') === ($right['presentation']->periodAttributes['start'] ?? 'PT0S')
            && $this->origin($left['presentation']) === $this->origin($right['presentation']);
    }

    /**
     * @param Context $primary
     * @param Context $candidate
     */
    private function canMerge(array $primary, array $candidate): bool
    {
        return !$primary['presentation']->complete || $candidate['presentation']->complete
            || $primary['metadata']['nativeAvailabilityStartTime'] !== null;
    }

    /** @param non-empty-list<array{job: TranscodeJob, context: Context}> $contexts */
    private function mergedPresentation(array $contexts): PublishedPresentation
    {
        $primary = $contexts[0]['context'];
        $complete = true;
        $attributes = $primary['presentation']->mpdAttributes;
        foreach ($contexts as $entry) {
            $presentation = $entry['context']['presentation'];
            $complete = $complete && $presentation->complete;
            $published = $presentation->mpdAttributes['publishTime'] ?? null;
            if ($published !== null && $published > ($attributes['publishTime'] ?? '')) {
                $attributes['publishTime'] = $published;
            }
        }
        if (!$complete) {
            $attributes['availabilityStartTime'] = $primary['metadata']['nativeAvailabilityStartTime']
                ?? $attributes['availabilityStartTime'] ?? '';
            $attributes['minimumUpdatePeriod'] ??= 'PT1S';
            unset($attributes['mediaPresentationDuration']);
        }
        return new PublishedPresentation($complete, $attributes, $primary['presentation']->periodAttributes, $primary['presentation']->tracks);
    }

    private function origin(PublishedPresentation $presentation): ?float
    {
        $track = $presentation->track(MediaTrack::Video);
        return $track === null || $track->segments === [] ? null : ($track->segments[0]->start - $track->presentationTimeOffset) / $track->timescale;
    }

    /** @param Context $context */
    private function bandwidth(array $context, QualityTier $tier, bool $hasAudio): int
    {
        return (int) ceil(($tier->maxBitrate + ($hasAudio ? $context['runtime']->desired->audioProfile->bitrate : 0)) * 1.05);
    }

    /** @param array<string, int|string> $query */
    private function trackUrl(TranscodeJob $job, MediaTrack $track, string $resource, int $generation, int $expiresAt, array $query = []): string
    {
        return $this->signed('/api/transcode/'.$job->getPublicId().'/tracks/'.$track->value.'/'.$resource, $generation, $expiresAt, $query);
    }

    /** @param array<string, int|string> $query */
    private function signed(string $path, int $generation, int $expiresAt, array $query = []): string
    {
        return $this->streamAuth->signUrlUntil($path.'?'.http_build_query($query + ['generation' => $generation]), $expiresAt)['url'];
    }

    private function read(string $path): ?string
    {
        $contents = @file_get_contents($path);
        return $contents === false ? null : $contents;
    }
}
