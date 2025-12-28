<?php

namespace App\Modules\Transcoder\Dto;

readonly class ServerStats
{
    public function __construct(
        public int   $activeSessions,
        public int   $totalSessionsCreated,
        public int   $activeTranscoders,
        public int   $completedTranscodes,
        public int   $failedTranscodes,
        public float $cpuUsage,
        public int   $memoryUsed,
        public int   $memoryTotal,
        public int   $uptimeSeconds,
        public array $additionalData = [],
    )
    {
    }

    /**
     * Create from array (API response)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            activeSessions: (int)($data['activeSessions'] ?? 0),
            totalSessionsCreated: (int)($data['totalSessionsCreated'] ?? 0),
            activeTranscoders: (int)($data['activeTranscoders'] ?? 0),
            completedTranscodes: (int)($data['completedTranscodes'] ?? 0),
            failedTranscodes: (int)($data['failedTranscodes'] ?? 0),
            cpuUsage: (float)($data['cpuUsage'] ?? 0.0),
            memoryUsed: (int)($data['memoryUsed'] ?? 0),
            memoryTotal: (int)($data['memoryTotal'] ?? 0),
            uptimeSeconds: (int)($data['uptimeSeconds'] ?? 0),
            additionalData: $data['additionalData'] ?? [],
        );
    }

    /**
     * Get memory usage as percentage
     */
    public function getMemoryUsagePercent(): float
    {
        if ($this->memoryTotal === 0) {
            return 0.0;
        }

        return ($this->memoryUsed / $this->memoryTotal) * 100;
    }

    /**
     * Get memory usage in human-readable format
     */
    public function getMemoryUsedFormatted(): string
    {
        return $this->formatBytes($this->memoryUsed);
    }

    /**
     * Format bytes to human-readable
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Get memory total in human-readable format
     */
    public function getMemoryTotalFormatted(): string
    {
        return $this->formatBytes($this->memoryTotal);
    }

    /**
     * Get uptime in human-readable format
     */
    public function getUptimeFormatted(): string
    {
        $seconds = $this->uptimeSeconds;
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($days > 0) {
            return sprintf('%dd %dh %dm', $days, $hours, $minutes);
        }

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', $minutes);
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'activeSessions'       => $this->activeSessions,
            'totalSessionsCreated' => $this->totalSessionsCreated,
            'activeTranscoders'    => $this->activeTranscoders,
            'completedTranscodes'  => $this->completedTranscodes,
            'failedTranscodes'     => $this->failedTranscodes,
            'cpuUsage'             => $this->cpuUsage,
            'memoryUsed'           => $this->memoryUsed,
            'memoryTotal'          => $this->memoryTotal,
            'uptimeSeconds'        => $this->uptimeSeconds,
            ...$this->additionalData,
        ];
    }
}
