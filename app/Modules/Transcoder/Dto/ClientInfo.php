<?php

namespace App\Modules\Transcoder\Dto;

readonly class ClientInfo
{
    public function __construct(
        public ?string $userAgent = null,
        public ?string $ipAddress = null,
        public ?string $sessionId = null,
        public ?int    $bitrate = null,
        public ?string $player = null,
        public array   $additionalData = [],
    )
    {
    }

    /**
     * Create from array (API response)
     */
    public static function fromArray(array $data): self
    {
        // Extract known keys, rest go to additionalData
        $knownKeys = ['userAgent', 'ipAddress', 'sessionId', 'bitrate', 'player'];
        $additionalData = array_diff_key($data, array_flip($knownKeys));

        return new self(
            userAgent: $data['userAgent'] ?? null,
            ipAddress: $data['ipAddress'] ?? null,
            sessionId: $data['sessionId'] ?? null,
            bitrate: isset($data['bitrate']) ? (int)($data['bitrate']) : null,
            player: $data['player'] ?? null,
            additionalData: $additionalData,
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return array_merge([
            'userAgent' => $this->userAgent,
            'ipAddress' => $this->ipAddress,
            'sessionId' => $this->sessionId,
            'bitrate'   => $this->bitrate,
            'player'    => $this->player,
        ], $this->additionalData);
    }
}
