<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\ValueObject\QualityTier;

interface TranscodeStoragePortInterface
{
    public function resolveJobDirectory(Uuid $videoId, QualityTier $qualityTier): string;

    public function resolveInitSegmentPath(Uuid $videoId, QualityTier $qualityTier): string;

    public function resolveSegmentPath(Uuid $videoId, QualityTier $qualityTier, int $segmentIndex): string;

    // --- Audio Track Paths ---

    public function resolveAudioDirectory(Uuid $videoId, string $language): string;

    public function resolveAudioInitSegmentPath(Uuid $videoId, string $language): string;

    public function resolveAudioSegmentPath(Uuid $videoId, string $language, int $segmentIndex): string;

    // --- Subtitle Paths ---

    public function resolveSubtitleDirectory(Uuid $videoId, string $language): string;

    public function resolveSubtitleSegmentPath(Uuid $videoId, string $language, string $segmentName): string;

    public function exists(string $path): bool;

    public function deleteDirectory(string $path): void;

    public function getDirectorySize(string $path): int;
}
