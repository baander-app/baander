<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

final readonly class StreamMetricsDto
{
    public function __construct(
        private string $jobId,
        private string $videoId,
        private string $qualityTier,
        private string $status,
        private int $totalSegments,
        private int $completedSegments,
        private float $progress,
        private ?float $encodingFps = null,
        private ?float $encodingSpeed = null,
        private ?int $outputSizeBytes = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'jobId' => $this->jobId,
            'videoId' => $this->videoId,
            'qualityTier' => $this->qualityTier,
            'status' => $this->status,
            'totalSegments' => $this->totalSegments,
            'completedSegments' => $this->completedSegments,
            'progress' => $this->progress,
        ];

        if ($this->encodingFps !== null) {
            $data['encodingFps'] = $this->encodingFps;
        }

        if ($this->encodingSpeed !== null) {
            $data['encodingSpeed'] = $this->encodingSpeed;
        }

        if ($this->outputSizeBytes !== null) {
            $data['outputSizeBytes'] = $this->outputSizeBytes;
        }

        return $data;
    }
}
