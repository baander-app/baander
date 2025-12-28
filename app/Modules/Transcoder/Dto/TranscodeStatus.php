<?php

namespace App\Modules\Transcoder\Dto;

readonly class TranscodeStatus
{
    public function __construct(
        public string  $jobId,
        public string  $state,
        public ?float  $progress = null, // 0.0 to 1.0
        public ?int    $segmentsProcessed = null,
        public ?int    $totalSegments = null,
        public ?int    $currentSegment = null,
        public ?float  $framesPerSecond = null,
        public ?float  $bitrate = null,
        public ?int    $estimatedTimeRemainingSeconds = null,
        public ?string $currentQuality = null,
        public array   $additionalData = [],
    )
    {
    }

    /**
     * Create from array (API response)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            jobId: $data['jobId'] ?? '',
            state: $data['state'] ?? 'unknown',
            progress: isset($data['progress']) ? (float)($data['progress']) : null,
            segmentsProcessed: isset($data['segmentsProcessed']) ? (int)($data['segmentsProcessed']) : null,
            totalSegments: isset($data['totalSegments']) ? (int)($data['totalSegments']) : null,
            currentSegment: isset($data['currentSegment']) ? (int)($data['currentSegment']) : null,
            framesPerSecond: isset($data['framesPerSecond']) ? (float)($data['framesPerSecond']) : null,
            bitrate: isset($data['bitrate']) ? (float)($data['bitrate']) : null,
            estimatedTimeRemainingSeconds: isset($data['estimatedTimeRemainingSeconds'])
                ? (int)($data['estimatedTimeRemainingSeconds'])
                : null,
            currentQuality: $data['currentQuality'] ?? null,
            additionalData: $data['additionalData'] ?? [],
        );
    }

    /**
     * Get progress as percentage (0-100)
     */
    public function getProgressPercent(): ?int
    {
        if ($this->progress === null) {
            return null;
        }

        return (int)round($this->progress * 100);
    }

    /**
     * Check if transcoding is complete
     */
    public function isComplete(): bool
    {
        return $this->state === 'completed' || $this->progress >= 1.0;
    }

    /**
     * Check if transcoding failed
     */
    public function isFailed(): bool
    {
        return $this->state === 'failed';
    }

    /**
     * Check if transcoding is active
     */
    public function isActive(): bool
    {
        return in_array($this->state, ['starting', 'processing', 'running'], true);
    }

    /**
     * Get ETA in human-readable format
     */
    public function getEtaFormatted(): ?string
    {
        if ($this->estimatedTimeRemainingSeconds === null) {
            return null;
        }

        $seconds = $this->estimatedTimeRemainingSeconds;
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $remainingSeconds);
        }

        return sprintf('%ds', $remainingSeconds);
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return array_merge([
            'jobId'                         => $this->jobId,
            'state'                         => $this->state,
            'progress'                      => $this->progress,
            'segmentsProcessed'             => $this->segmentsProcessed,
            'totalSegments'                 => $this->totalSegments,
            'currentSegment'                => $this->currentSegment,
            'framesPerSecond'               => $this->framesPerSecond,
            'bitrate'                       => $this->bitrate,
            'estimatedTimeRemainingSeconds' => $this->estimatedTimeRemainingSeconds,
            'currentQuality'                => $this->currentQuality,
        ], $this->additionalData);
    }
}
