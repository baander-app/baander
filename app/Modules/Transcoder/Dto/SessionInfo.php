<?php

namespace App\Modules\Transcoder\Dto;

readonly class SessionInfo
{
    /**
     * @param string $id Session ID
     * @param int $startSegment Starting segment number
     * @param string $variantId Quality variant ID
     * @param int $height Video height
     * @param string $format Stream format (hls, dash)
     * @param int $lastUsed Last activity timestamp
     * @param bool $isPaused Whether session is paused
     * @param int $maxSegment Maximum segment available
     * @param array<int, int> $lastRequestedSegments Map of segment -> timestamp
     * @param array<string, UserRequest> $activeUsers Map of userId -> UserRequest
     * @param int $userCount Number of active users
     * @param int $createdAt Session creation timestamp
     * @param int $restartBlockedUntil Timestamp until which restart is blocked
     * @param array $clientData Additional client data
     */
    public function __construct(
        public string $id,
        public int    $startSegment,
        public string $variantId,
        public int    $height,
        public string $format,
        public int    $lastUsed,
        public bool   $isPaused,
        public int    $maxSegment,
        public array  $lastRequestedSegments = [],
        public array  $activeUsers = [],
        public int    $userCount = 0,
        public int    $createdAt = 0,
        public int    $restartBlockedUntil = 0,
        public array  $clientData = [],
    )
    {
    }

    /**
     * Create from array (API response)
     */
    public static function fromArray(array $data): self
    {
        // Parse lastRequestedSegments map
        $lastRequestedSegments = [];
        if (isset($data['lastRequestedSegments']) && is_array($data['lastRequestedSegments'])) {
            foreach ($data['lastRequestedSegments'] as $segment => $timestamp) {
                $lastRequestedSegments[(int)$segment] = (int)$timestamp;
            }
        }

        // Parse activeUsers map
        $activeUsers = [];
        if (isset($data['activeUsers']) && is_array($data['activeUsers'])) {
            foreach ($data['activeUsers'] as $userId => $userData) {
                $activeUsers[$userId] = UserRequest::fromArray($userData);
            }
        }

        return new self(
            id: $data['id'] ?? '',
            startSegment: (int)($data['startSegment'] ?? 0),
            variantId: $data['variantId'] ?? '',
            height: (int)($data['height'] ?? 0),
            format: $data['format'] ?? 'hls',
            lastUsed: (int)($data['lastUsed'] ?? 0),
            isPaused: (bool)($data['isPaused'] ?? false),
            maxSegment: (int)($data['maxSegment'] ?? 0),
            lastRequestedSegments: $lastRequestedSegments,
            activeUsers: $activeUsers,
            userCount: (int)($data['userCount'] ?? 0),
            createdAt: (int)($data['createdAt'] ?? 0),
            restartBlockedUntil: (int)($data['restartBlockedUntil'] ?? 0),
            clientData: $data['clientData'] ?? [],
        );
    }

    /**
     * Get session age in seconds
     */
    public function getAgeSeconds(): int
    {
        if ($this->createdAt === 0) {
            return 0;
        }

        return time() - $this->createdAt;
    }

    /**
     * Check if session is stale (inactive for more than 2 minutes)
     */
    public function isStale(int $idleTimeoutSeconds = 120): bool
    {
        return $this->getIdleSeconds() > $idleTimeoutSeconds;
    }

    /**
     * Get idle time in seconds
     */
    public function getIdleSeconds(): int
    {
        if ($this->lastUsed === 0) {
            return 0;
        }

        return time() - $this->lastUsed;
    }

    /**
     * Check if session can be restarted
     */
    public function canRestart(): bool
    {
        return time() >= $this->restartBlockedUntil;
    }

    /**
     * Get restart blocked time remaining in seconds
     */
    public function getRestartBlockedSecondsRemaining(): int
    {
        $remaining = $this->restartBlockedUntil - time();

        return max(0, $remaining);
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        $activeUsersArray = array_map(function ($userRequest) {
            return $userRequest->toArray();
        }, $this->activeUsers);

        return [
            'id'                    => $this->id,
            'startSegment'          => $this->startSegment,
            'variantId'             => $this->variantId,
            'height'                => $this->height,
            'format'                => $this->format,
            'lastUsed'              => $this->lastUsed,
            'isPaused'              => $this->isPaused,
            'maxSegment'            => $this->maxSegment,
            'lastRequestedSegments' => $this->lastRequestedSegments,
            'activeUsers'           => $activeUsersArray,
            'userCount'             => $this->userCount,
            'createdAt'             => $this->createdAt,
            'restartBlockedUntil'   => $this->restartBlockedUntil,
            'clientData'            => $this->clientData,
        ];
    }
}
