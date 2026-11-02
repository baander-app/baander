<?php

declare(strict_types=1);

namespace App\Session\Application\Port;

use App\Session\Domain\Model\ListeningSession\ListeningSession;

/** Storage operations share the transaction port's connection. No external delivery runs here. */
interface PlaybackStoreInterface
{
    public function lockSession(string $userId): ListeningSession;
    public function saveSession(ListeningSession $session): void;
    /** Retire only unavailable managed browser execution with a durable, fenced quiescence acknowledgement. */
    public function retireConfirmedPausedBrowser(ListeningSession $session): bool;
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
    /** @return array{id:string,request_id:string,outcome:?string,rejection_reason:?string}|null */
    public function requestedCommand(string $sessionId, string $requestId): ?array;
    public function acknowledgeCommand(string $commandId, string $outcome, ?string $rejectionReason = null): void;
    /** @return array{id:string,request_id:string,state:string,rejection_reason:?string}|null */
    public function requestedTransfer(string $sessionId, string $requestId): ?array;
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
    /** @return array<string,mixed> */
    public function localRecovery(string $owner, string $incarnationId, string $tabId, string $credential): array;
    /** @param array<string,mixed> $result */
    public function completeLocalRecovery(string $incarnationId, array $result): void;
    public function supersedeIncarnationCommands(string $incarnationId): void;
    public function sourceQuiesced(string $attemptId, string $sourceId): bool;
}
