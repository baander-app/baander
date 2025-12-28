<?php

namespace App\Modules\Transcoder\Dto;

readonly class TranscodeJob
{
    public function __construct(
        public string  $jobId,
        public string  $videoId,
        public string  $status,
        public ?string $format = null,
        public ?array  $qualities = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public ?string $startedAt = null,
        public ?string $completedAt = null,
        public ?string $errorMessage = null,
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
            videoId: $data['videoId'] ?? '',
            status: $data['status'] ?? 'unknown',
            format: $data['format'] ?? null,
            qualities: $data['qualities'] ?? null,
            createdAt: $data['createdAt'] ?? null,
            updatedAt: $data['updatedAt'] ?? null,
            startedAt: $data['startedAt'] ?? null,
            completedAt: $data['completedAt'] ?? null,
            errorMessage: $data['errorMessage'] ?? null,
        );
    }

    /**
     * Check if job is running
     */
    public function isRunning(): bool
    {
        return in_array($this->status, ['starting', 'processing', 'running'], true);
    }

    /**
     * Check if job completed successfully
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if job failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if job is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'jobId'        => $this->jobId,
            'videoId'      => $this->videoId,
            'status'       => $this->status,
            'format'       => $this->format,
            'qualities'    => $this->qualities,
            'createdAt'    => $this->createdAt,
            'updatedAt'    => $this->updatedAt,
            'startedAt'    => $this->startedAt,
            'completedAt'  => $this->completedAt,
            'errorMessage' => $this->errorMessage,
        ];
    }
}
