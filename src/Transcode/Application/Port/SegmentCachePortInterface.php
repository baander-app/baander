<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface SegmentCachePortInterface
{
    public function get(Uuid $jobId, int $segmentIndex): ?string;

    public function put(Uuid $jobId, int $segmentIndex, string $segmentData, int $ttlSeconds = 86400): void;

    public function incrementRef(Uuid $jobId, int $segmentIndex): void;

    public function decrementRef(Uuid $jobId, int $segmentIndex): void;

    public function evictLeastRecentlyUsed(int $maxEntries): void;

    public function getByType(Uuid $jobId, string $type, int $segmentIndex): ?string;

    public function putByType(Uuid $jobId, string $type, int $segmentIndex, string $segmentData, int $ttlSeconds = 86400): void;

    public function clear(): void;
}
