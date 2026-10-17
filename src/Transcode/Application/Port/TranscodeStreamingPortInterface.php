<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface TranscodeStreamingPortInterface
{
    /** @return array{jobId: string, generation: int, revision: int, ready: bool, position: float, status: string, startTime: ?float, duration: ?float}|null */
    public function getPlaybackState(Uuid $videoId, ?string $jobPublicId = null): ?array;

    public function getMasterManifest(Uuid $videoId, int $generation, ?string $jobPublicId = null): string;

    public function getMediaManifest(PublicId $jobPublicId, string $audioProfileName, int $generation): string;

    public function getSegment(PublicId $jobPublicId, int $segmentIndex, int $generation): ?string;

    public function getInitSegment(PublicId $jobPublicId, int $generation): ?string;

    public function getSegmentPath(PublicId $jobPublicId, int $segmentIndex, int $generation): ?string;

    /**
     * Resolve the availability-table key for a video segment.
     *
     * Returns [jobId, tierKey, path] so the HTTP layer can check
     * SegmentAvailabilityInterface::isReady() before falling back to
     * file-stat polling. Returns null when the job/segment is unknown.
     *
     * @return array{jobId: Uuid, tierKey: string, path: string}|null
     */
    public function resolveVideoSegmentAvailability(PublicId $jobPublicId, int $segmentIndex, int $generation): ?array;

    public function getInitSegmentPath(PublicId $jobPublicId, int $generation): ?string;

    public function getDashManifest(Uuid $videoId, int $generation, ?string $jobPublicId = null): string;

    /**
     * @return list<array{name: string, height: int, width: int, bitrate: int, codec: string}>
     */
    public function getQualityLadderForVideo(Uuid $videoId): array;

    // --- Subtitle Delivery ---

    public function getSubtitleManifest(PublicId $jobPublicId, string $language, int $generation): string;

    public function getSubtitleSegment(PublicId $jobPublicId, string $language, string $segmentName, int $generation): ?string;

    public function getSubtitleSegmentPath(PublicId $jobPublicId, string $language, string $segmentName, int $generation): ?string;
}
