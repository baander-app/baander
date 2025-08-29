<?php

namespace App\Models\Concerns;

use App\Models\Data\FormattedLibraryStats;
use App\Models\Data\LibraryStats;
use App\Models\Enums\MetaKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

trait HasLibraryStats
{
    /**
     * Get formatted library statistics and store them in meta
     */
    public function getFormattedStats(): FormattedLibraryStats
    {
        // Check if already computed
        $cached = $this->getMetaAsType(MetaKey::FORMATTED_STATS, FormattedLibraryStats::class);
        if ($cached !== null) {
            return $cached;
        }

        $startTime = microtime(true);
        $rawStats = $this->getRawStats();

        $formattedStats = FormattedLibraryStats::fromRawStats(
            $rawStats,
            fn(int $seconds) => $this->formatDuration($seconds),
            fn(int $bytes) => $this->formatBytes($bytes)
        );

        $computationTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        // Store in meta with type safety
        $this->setMeta(MetaKey::FORMATTED_STATS, $formattedStats)
            ->setMeta(MetaKey::STATS_LOADED_AT, Carbon::now())
            ->setMeta(MetaKey::COMPUTATION_TIME, $computationTime);

        return $formattedStats;
    }

    /**
     * Get raw library statistics and store them in meta
     */
    public function getRawStats(): LibraryStats
    {
        // Check if already computed
        $cached = $this->getMetaAsType(MetaKey::RAW_STATS, LibraryStats::class);
        if ($cached !== null) {
            return $cached;
        }

        $statsArray = $this->getStats();
        $rawStats = LibraryStats::fromArray($statsArray);

        // Store in meta with type safety
        $this->setMeta(MetaKey::RAW_STATS, $rawStats);

        return $rawStats;
    }

    /**
     * Get comprehensive library statistics from database
     */
    private function getStats(): array
    {
        $result = DB::selectOne('SELECT get_library_stats(?) as stats', [$this->id]);
        return json_decode($result->stats, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Format duration in seconds to human readable format
     */
    private function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remainingSeconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $remainingSeconds);
        }

        return sprintf('%d:%02d', $minutes, $remainingSeconds);
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
