<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface TranscodeStreamingPortInterface
{
    public function getMasterManifest(Uuid $videoId): string;

    public function getMediaManifest(PublicId $jobPublicId, string $audioProfileName): string;

    public function getSegment(PublicId $jobPublicId, int $segmentIndex): ?string;

    public function getInitSegment(PublicId $jobPublicId): ?string;

    public function getSegmentPath(PublicId $jobPublicId, int $segmentIndex): ?string;

    public function getInitSegmentPath(PublicId $jobPublicId): ?string;

    public function getDashManifest(Uuid $videoId): string;

    public function getQualityLadderForVideo(Uuid $videoId): array;

    // --- Separate Audio Track Delivery ---

    public function getAudioManifest(PublicId $jobPublicId, string $language): string;

    public function getAudioInitSegment(PublicId $jobPublicId, string $language): ?string;

    public function getAudioSegment(PublicId $jobPublicId, string $language, int $segmentIndex): ?string;

    public function getAudioInitSegmentPath(PublicId $jobPublicId, string $language): ?string;

    public function getAudioSegmentPath(PublicId $jobPublicId, string $language, int $segmentIndex): ?string;

    // --- Subtitle Delivery ---

    public function getSubtitleManifest(PublicId $jobPublicId, string $language): string;

    public function getSubtitleSegment(PublicId $jobPublicId, string $language, string $segmentName): ?string;

    public function getSubtitleSegmentPath(PublicId $jobPublicId, string $language, string $segmentName): ?string;
}
