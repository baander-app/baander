<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

trait HasLibraryStats
{
    /**
     * Get formatted library statistics with human-readable values
     */
    public function getFormattedStats(): array
    {
        $stats = $this->getStats();

        return [
            'total_songs'    => number_format($stats['total_songs']),
            'total_albums'   => number_format($stats['total_albums']),
            'total_artists'  => number_format($stats['total_artists']),
            'total_genres'   => number_format($stats['total_genres']),
            'total_duration' => $this->formatDuration($stats['total_duration']),
            'total_size'     => $this->formatBytes($stats['total_size']),
            'library_name'   => $stats['library_name'],
        ];
    }

    /**
     * Get comprehensive library statistics
     */
    public function getStats(): array
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