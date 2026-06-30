<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Cache;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\SegmentCachePortInterface;

final class InMemorySegmentCache implements SegmentCachePortInterface
{
    /** @var array<string, array{data: string, refs: int, expires_at: int}> */
    private array $cache = [];

    /** @var array<string, int> Last access timestamp */
    private array $accessOrder = [];

    public function get(Uuid $jobId, int $segmentIndex): ?string
    {
        $key = $this->key($jobId, $segmentIndex);

        if (!isset($this->cache[$key])) {
            return null;
        }

        if ($this->cache[$key]['expires_at'] < time()) {
            unset($this->cache[$key], $this->accessOrder[$key]);
            return null;
        }

        $this->accessOrder[$key] = time();

        return $this->cache[$key]['data'];
    }

    public function put(Uuid $jobId, int $segmentIndex, string $segmentData, int $ttlSeconds = 86400): void
    {
        $key = $this->key($jobId, $segmentIndex);

        $this->cache[$key] = [
            'data' => $segmentData,
            'refs' => 1,
            'expires_at' => time() + $ttlSeconds,
        ];
        $this->accessOrder[$key] = time();
    }

    public function incrementRef(Uuid $jobId, int $segmentIndex): void
    {
        $key = $this->key($jobId, $segmentIndex);

        if (isset($this->cache[$key])) {
            $this->cache[$key]['refs']++;
            $this->accessOrder[$key] = time();
        }
    }

    public function decrementRef(Uuid $jobId, int $segmentIndex): void
    {
        $key = $this->key($jobId, $segmentIndex);

        if (!isset($this->cache[$key])) {
            return;
        }

        $this->cache[$key]['refs']--;

        if ($this->cache[$key]['refs'] <= 0) {
            unset($this->cache[$key], $this->accessOrder[$key]);
        }
    }

    public function evictLeastRecentlyUsed(int $maxEntries): void
    {
        if (count($this->cache) <= $maxEntries) {
            return;
        }

        asort($this->accessOrder);
        $toEvict = count($this->cache) - $maxEntries;

        foreach (array_keys($this->accessOrder) as $key) {
            if ($toEvict <= 0) {
                break;
            }
            unset($this->cache[$key], $this->accessOrder[$key]);
            $toEvict--;
        }
    }

    public function clear(): void
    {
        $this->cache = [];
        $this->accessOrder = [];
    }

    public function getByType(Uuid $jobId, string $type, int $segmentIndex): ?string
    {
        $key = $this->typedKey($jobId, $type, $segmentIndex);

        if (!isset($this->cache[$key])) {
            return null;
        }

        if ($this->cache[$key]['expires_at'] < time()) {
            unset($this->cache[$key], $this->accessOrder[$key]);
            return null;
        }

        $this->accessOrder[$key] = time();

        return $this->cache[$key]['data'];
    }

    public function putByType(Uuid $jobId, string $type, int $segmentIndex, string $segmentData, int $ttlSeconds = 86400): void
    {
        $key = $this->typedKey($jobId, $type, $segmentIndex);

        $this->cache[$key] = [
            'data' => $segmentData,
            'refs' => 1,
            'expires_at' => time() + $ttlSeconds,
        ];
        $this->accessOrder[$key] = time();
    }

    private function key(Uuid $jobId, int $segmentIndex): string
    {
        return sprintf('%s:%d', $jobId->toString(), $segmentIndex);
    }

    private function typedKey(Uuid $jobId, string $type, int $segmentIndex): string
    {
        return sprintf('%s:%s:%d', $jobId->toString(), $type, $segmentIndex);
    }
}
