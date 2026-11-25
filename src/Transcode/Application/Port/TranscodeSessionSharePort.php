<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\HeldTranscodeSession;
use DateTimeImmutable;

/** The sessions each user holds and the video executions that occupy the slots, for a start that needs room. */
interface TranscodeSessionSharePort
{
    /**
     * Serializes the session starts of one user until the transaction ends. Taken first in the
     * transaction, before any row lock. Requires an open transaction.
     */
    public function lockUser(Uuid $userId): void;

    /**
     * The user's held sessions, oldest sign of life first.
     *
     * @return list<HeldTranscodeSession>
     */
    public function heldBy(Uuid $userId): array;

    /** Video executions that run in a video slot, or wait for one within their admission deadline. */
    public function occupiedVideoSlots(): int;

    /**
     * The held session with the oldest sign of life whose execution occupies a video slot, if that
     * sign of life is no later than the given instant.
     *
     * @param list<Uuid> $excluding sessions that are not candidates
     */
    public function oldestSlotHolder(DateTimeImmutable $silentSince, array $excluding): ?HeldTranscodeSession;

    /**
     * Locks the session's execution and then its row, and reads it again under those locks.
     *
     * @return HeldTranscodeSession|null null when the session no longer counts toward its user's share
     */
    public function lock(Uuid $sessionId): ?HeldTranscodeSession;

    /** Ends a locked session and releases its job without cancelling it, as stale session expiry does. */
    public function end(HeldTranscodeSession $session): void;
}
