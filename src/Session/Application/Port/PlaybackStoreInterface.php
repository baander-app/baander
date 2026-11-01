<?php

declare(strict_types=1);

namespace App\Session\Application\Port;

use App\Session\Domain\Model\ListeningSession\ListeningSession;

/** Storage operations share the transaction port's connection. No external delivery runs here. */
interface PlaybackStoreInterface
{
    public function lockSession(string $userId): ListeningSession;
    public function saveSession(ListeningSession $session): void;
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function register(string $userId, array $input): array;
    /** @return array<string,mixed> */
    public function endpoint(string $userId, string $incarnationId, ?string $capability = null): array;
    /** @return array<string,mixed>|null */
    public function currentTransfer(string $sessionId): ?array;
    /** @param array<string,mixed> $transfer */
    public function saveTransfer(array $transfer): void;
    /** @param array<string,mixed> $command */
    public function insertCommand(array $command): void;
    /** @return array<string,mixed> */
    public function command(string $userId, string $commandId): array;
    public function acknowledgeCommand(string $commandId, string $outcome): void;
    public function cancelCommands(string $attemptId): void;
    public function hasPendingControl(string $sessionId): bool;
    /** @return array<string,mixed>|null */
    public function pendingControl(string $sessionId): ?array;
    public function supersedeControls(string $sessionId): void;
    public function rememberRequest(string $sessionId, string $requestId, string $digest): bool;
    /** @return array<string,mixed> */
    public function snapshot(ListeningSession $session, ?string $incarnationId): array;
    public function observe(string $incarnationId, string $state, float $position, ?float $volume = null): void;
    public function observeVolume(string $incarnationId, float $volume): void;
}
