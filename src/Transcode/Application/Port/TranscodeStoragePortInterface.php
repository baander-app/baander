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

    /**
     * Absolute path of the transcode cache root (the directory that holds
     * one subdir per videoId).
     */
    public function getBasePath(): string;

    /**
     * List the per-video cache directories that currently exist on disk.
     *
     * Each entry is the basename of a direct child of the cache root (a
     * videoId string). Returns an empty array when the root does not exist.
     * The audio rendition directory is not a video and is not listed.
     *
     * @return list<string>
     */
    public function getVideoDirectories(): array;

    // --- Track Audio Renditions ---

    /**
     * List the tracks that have an audio rendition directory on disk.
     *
     * @return list<string> track keys
     */
    public function getAudioRenditionDirectories(): array;

    /** Absolute path of the directory holding one track's audio renditions. */
    public function resolveAudioRenditionDirectory(string $trackKey): string;

    /**
     * Whether an encoder has written to one of the track's renditions at or
     * after the given instant, meaning an encode is (or was until recently) running.
     */
    public function isAudioRenditionEncodingSince(string $trackKey, \DateTimeImmutable $since): bool;
}
