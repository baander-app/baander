<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Transcode;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\SegmentCachePortInterface;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Decorator that caches segment data in InMemorySegmentCache.
 *
 * Wraps TranscodeStreamingPortInterface: checks cache before delegating
 * to inner service, caches results on miss. All other methods delegate
 * directly without caching.
 *
 * Follows decorator pattern from CachedAccessTokenRepository.
 */
final readonly class CachedTranscodeStreamingService implements TranscodeStreamingPortInterface
{
    private const MAX_CACHE_ENTRIES = 500;

    public function __construct(
        private TranscodeStreamingPortInterface $inner,
        private SegmentCachePortInterface $cache,
        private TranscodeJobRepositoryInterface $jobRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function getMasterManifest(Uuid $videoId): string
    {
        return $this->inner->getMasterManifest($videoId);
    }

    public function getMediaManifest(PublicId $jobPublicId, string $audioProfileName): string
    {
        return $this->inner->getMediaManifest($jobPublicId, $audioProfileName);
    }

    public function getSegment(PublicId $jobPublicId, int $segmentIndex): ?string
    {
        // Resolve internal job ID for cache key
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        if ($job === null) {
            return null;
        }

        $jobId = $job->getId();

        // Check cache first
        $cached = $this->cache->get($jobId, $segmentIndex);
        if ($cached !== null) {
            $this->cache->incrementRef($jobId, $segmentIndex);

            return $cached;
        }

        // Cache miss — delegate to inner service
        $data = $this->inner->getSegment($jobPublicId, $segmentIndex);

        if ($data !== null) {
            $this->cache->put($jobId, $segmentIndex, $data);
            $this->cache->evictLeastRecentlyUsed(self::MAX_CACHE_ENTRIES);
        }

        return $data;
    }

    public function getSegmentPath(PublicId $jobPublicId, int $segmentIndex): ?string
    {
        // Path resolution bypasses cache — used for zero-copy streaming
        return $this->inner->getSegmentPath($jobPublicId, $segmentIndex);
    }

    public function getInitSegment(PublicId $jobPublicId): ?string
    {
        return $this->inner->getInitSegment($jobPublicId);
    }

    public function getInitSegmentPath(PublicId $jobPublicId): ?string
    {
        return $this->inner->getInitSegmentPath($jobPublicId);
    }

    public function getDashManifest(Uuid $videoId): string
    {
        return $this->inner->getDashManifest($videoId);
    }

    public function getQualityLadderForVideo(Uuid $videoId): array
    {
        return $this->inner->getQualityLadderForVideo($videoId);
    }

    public function getAudioManifest(PublicId $jobPublicId, string $language): string
    {
        return $this->inner->getAudioManifest($jobPublicId, $language);
    }

    public function getAudioInitSegment(PublicId $jobPublicId, string $language): ?string
    {
        return $this->inner->getAudioInitSegment($jobPublicId, $language);
    }

    public function getAudioInitSegmentPath(PublicId $jobPublicId, string $language): ?string
    {
        return $this->inner->getAudioInitSegmentPath($jobPublicId, $language);
    }

    public function getAudioSegment(PublicId $jobPublicId, string $language, int $segmentIndex): ?string
    {
        $job = $this->jobRepository->findByPublicId($jobPublicId);
        if ($job === null) {
            return null;
        }

        $cached = $this->cache->getByType($job->getId(), 'audio', $segmentIndex);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->inner->getAudioSegment($jobPublicId, $language, $segmentIndex);

        if ($data !== null) {
            $this->cache->putByType($job->getId(), 'audio', $segmentIndex, $data);
            $this->cache->evictLeastRecentlyUsed(self::MAX_CACHE_ENTRIES);
        }

        return $data;
    }

    public function getAudioSegmentPath(PublicId $jobPublicId, string $language, int $segmentIndex): ?string
    {
        return $this->inner->getAudioSegmentPath($jobPublicId, $language, $segmentIndex);
    }

    public function getSubtitleManifest(PublicId $jobPublicId, string $language): string
    {
        return $this->inner->getSubtitleManifest($jobPublicId, $language);
    }

    public function getSubtitleSegment(PublicId $jobPublicId, string $language, string $segmentName): ?string
    {
        return $this->inner->getSubtitleSegment($jobPublicId, $language, $segmentName);
    }

    public function getSubtitleSegmentPath(PublicId $jobPublicId, string $language, string $segmentName): ?string
    {
        return $this->inner->getSubtitleSegmentPath($jobPublicId, $language, $segmentName);
    }
}
