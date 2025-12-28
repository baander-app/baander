<?php

namespace App\Modules\Transcoder\Dto;

readonly class UserRequest
{
    public function __construct(
        public string      $userId,
        public int         $segment,
        public int         $timestamp,
        public int         $requestCount,
        public ?ClientInfo $clientInfo = null,
    )
    {
    }

    /**
     * Create from array (API response)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            userId: $data['userId'] ?? '',
            segment: (int)($data['segment'] ?? 0),
            timestamp: (int)($data['timestamp'] ?? 0),
            requestCount: (int)($data['requestCount'] ?? 0),
            clientInfo: isset($data['clientInfo']) ? ClientInfo::fromArray($data['clientInfo']) : null,
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'userId'       => $this->userId,
            'segment'      => $this->segment,
            'timestamp'    => $this->timestamp,
            'requestCount' => $this->requestCount,
            'clientInfo'   => $this->clientInfo?->toArray(),
        ];
    }
}
