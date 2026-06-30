<?php

declare(strict_types=1);

namespace App\Session\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface SessionPortInterface
{
    /**
     * Get the current listening session for a user.
     *
     * @return array<string, mixed>|null
     */
    public function getSession(Uuid $userId): ?array;

    /**
     * Sync playback state for a user's session.
     *
     * @param array<string, mixed> $queue
     *
     * @return array<string, mixed>
     */
    public function syncSession(Uuid $userId, Uuid $deviceId, array $queue, int $currentTrackIndex, float $position, string $playbackState): array;

    /**
     * Claim the session for a specific device.
     *
     * @return array<string, mixed>
     */
    public function claimSession(Uuid $userId, Uuid $deviceId): array;

    /**
     * Create a new listening session.
     *
     * @param array<string, mixed> $queue
     *
     * @return array<string, mixed>
     */
    public function createSession(Uuid $userId, array $queue, int $currentTrackIndex, float $position): array;

    /**
     * Register (or update) a device for a user.
     */
    public function registerDevice(Uuid $userId, Uuid $deviceId, string $name): void;

    /**
     * Get all devices registered for a user.
     *
     * @return list<array<string, mixed>>
     */
    public function getDevices(Uuid $userId): array;

    /**
     * Rename a device.
     */
    public function renameDevice(Uuid $userId, Uuid $deviceId, string $name): void;

    /**
     * Forget (remove) a device.
     */
    public function forgetDevice(Uuid $userId, Uuid $deviceId): void;
}
