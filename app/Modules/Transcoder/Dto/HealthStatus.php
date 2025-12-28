<?php

namespace App\Modules\Transcoder\Dto;

readonly class HealthStatus
{
    public function __construct(
        public string  $status,
        public string  $version,
        public bool    $healthy = true,
        public ?string $message = null,
    )
    {
    }

    /**
     * Create from array (API response)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: $data['status'] ?? 'unknown',
            version: $data['version'] ?? '0.0.0',
            healthy: $data['healthy'] ?? true,
            message: $data['message'] ?? null,
        );
    }

    /**
     * Check if system is healthy
     */
    public function isHealthy(): bool
    {
        return $this->healthy && $this->status === 'ok';
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'status'  => $this->status,
            'version' => $this->version,
            'healthy' => $this->healthy,
            'message' => $this->message,
        ];
    }
}
