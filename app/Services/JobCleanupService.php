<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class JobCleanupService
{
    public function clearStuckJobLocks(bool $dryRun = false, int $maxAgeHours = 4): array
    {
        $lockPattern = 'laravel_unique_job:*';
        $keys = Cache::getRedis()->keys($lockPattern);
        $maxAgeSeconds = $maxAgeHours * 3600;

        $clearedLocks = [];

        foreach ($keys as $key) {
            $ttl = Cache::getRedis()->ttl($key);

            // If key has no expiration or is older than max age
            if ($ttl === -1 || $ttl > $maxAgeSeconds) {
                $cleanKey = str_replace(config('cache.prefix') . ':', '', $key);

                if (!$dryRun) {
                    Cache::forget($cleanKey);
                }

                $clearedLocks[] = [
                    'key'       => $key,
                    'ttl'       => $ttl,
                    'age_hours' => $ttl > 0 ? round($ttl / 3600, 2) : 'no expiration',
                ];
            }
        }

        return $clearedLocks;
    }

    public function getOldFailedJobs(int $hoursOld = 24): array
    {
        return DB::table('failed_jobs')
            ->where('failed_at', '<', now()->subHours($hoursOld))
            ->select(['id', 'queue', 'payload', 'failed_at'])
            ->get()
            ->toArray();
    }

    public function clearFailedJobs(int $hoursOld = 24, bool $dryRun = false): int
    {
        $query = DB::table('failed_jobs')
            ->where('failed_at', '<', now()->subHours($hoursOld));

        $count = $query->count();

        if (!$dryRun && $count > 0) {
            $query->delete();
        }

        return $count;
    }

    public function clearSpecificJobLock(string $jobClass, mixed $uniqueId): bool
    {
        $lockKey = "laravel_unique_job:{$jobClass}:{$uniqueId}";

        return Cache::forget($lockKey);
    }

    public function getJobLockInfo(string $jobClass, mixed $uniqueId): ?array
    {
        $lockKey = "laravel_unique_job:{$jobClass}:{$uniqueId}";
        $fullKey = config('cache.prefix') . ':' . $lockKey;

        if (!Cache::has($lockKey)) {
            return null;
        }

        $ttl = Cache::getRedis()->ttl($fullKey);

        return [
            'key'       => $lockKey,
            'ttl'       => $ttl,
            'age_hours' => $ttl > 0 ? round($ttl / 3600, 2) : 'no expiration',
            'exists'    => true,
        ];
    }

    public function getJobLocks(): array
    {
        $lockPattern = 'laravel_unique_job:*';
        $keys = Cache::getRedis()->keys($lockPattern);
        $locks = [];

        foreach ($keys as $key) {
            $ttl = Cache::getRedis()->ttl($key);
            $cleanKey = str_replace(config('cache.prefix') . ':', '', $key);

            $locks[] = [
                'key'       => $cleanKey,
                'full_key'  => $key,
                'ttl'       => $ttl,
                'age_hours' => $ttl > 0 ? round($ttl / 3600, 2) : 'no expiration',
            ];
        }

        return $locks;
    }

    public function getCleanupSummary(bool $dryRun = false): array
    {
        $stuckLocks = $this->clearStuckJobLocks($dryRun);
        $oldFailedJobs = $this->getOldFailedJobs();

        return [
            'stuck_locks'     => [
                'count' => count($stuckLocks),
                'locks' => $stuckLocks,
            ],
            'old_failed_jobs' => [
                'count' => count($oldFailedJobs),
                'jobs'  => $oldFailedJobs,
            ],
            'dry_run'         => $dryRun,
        ];
    }
}