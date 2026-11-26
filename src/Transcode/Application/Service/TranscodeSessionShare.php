<?php

declare(strict_types=1);

namespace App\Transcode\Application\Service;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\HeldTranscodeSession;
use App\Transcode\Application\Exception\TranscodeSessionLimitException;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeSessionSharePort;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Bounds how many transcode sessions one user holds, and lets a start take the room of sessions
 * nobody is watching.
 *
 * A session counts from its start until it ends, whether its execution runs, waits for a video slot
 * or has finished: a seek on a finished presentation encodes again without a new start. A start past
 * the user's share ends that user's own sessions with the oldest sign of life once they have reported
 * nothing for two minutes, and is refused when too few have. A start that finds no video slot free
 * ends the slot holder with the oldest sign of life, whoever holds it, once it has reported nothing
 * for seventy minutes; otherwise it waits for a slot. The sessions of the playback the start
 * continues, the same video and client session, never give way to it.
 *
 * Lock order: the user's advisory lock first in the start's transaction, then the start's video row,
 * then the execution row and session row of every session the start may end, in session id order,
 * then job rows. Ending a session takes its execution row without its video row, so a start never
 * waits for a second video row while it holds one.
 */
final readonly class TranscodeSessionShare
{
    /** How long a user's own session reports nothing before that user's next start may end it. */
    public const int OWN_SILENCE_SECONDS = 120;

    /**
     * How long a session reports nothing before a start that finds no free video slot may end it,
     * whoever holds it. A paused player renews its sign of life once an hour when it signs its stream
     * URL, so this outlasts one renewal and a paused film that still signs keeps its slot.
     */
    public const int SLOT_SILENCE_SECONDS = 4200;

    public function __construct(
        private TranscodeSessionSharePort $store,
        private TranscodeExecutionOwnershipPort $ownership,
        private TransactionPortInterface $transaction,
        private SystemSettingsPortInterface $settings,
        private DeploymentProfile $profile,
        private ClockInterface $clock,
    ) {
    }

    /** Serializes the user's starts until the transaction ends; the first statement of a start's transaction. */
    public function lock(Uuid $userId): void
    {
        $this->store->lockUser($userId);
    }

    /**
     * Makes room for one new session of the playback, inside the start's transaction after lock().
     * Every session it ends stays ended only if that transaction commits.
     *
     * @throws TranscodeSessionLimitException when the user's other sessions cannot give way
     */
    public function makeRoom(Uuid $userId, Uuid $videoId, string $requestId): void
    {
        $now = $this->clock->now();
        $held = $this->store->heldBy($userId);
        $excess = $this->excess($held, 1);
        $candidates = $this->ownCandidates($held, $videoId, $requestId, $excess, $now);
        $occupied = $this->store->occupiedVideoSlots();
        // Whichever candidates give way, a start that finds every video slot taken may need a slot holder.
        $slotCandidate = $occupied >= $this->videoSlots()
            ? $this->store->oldestSlotHolder(self::before($now, self::SLOT_SILENCE_SECONDS), self::ids([...$candidates, ...self::playback($held, $videoId, $requestId)]))
            : null;
        $locked = $this->lockInSessionOrder([...$candidates, ...array_filter([$slotCandidate])]);
        $stillHeld = array_intersect_key($locked, self::keyed($candidates));
        // A candidate that ended since it was read has freed its room; one that reported since keeps its own.
        $needed = $excess - count($candidates) + count($stillHeld);
        $silent = self::silentSince($stillHeld, self::before($now, self::OWN_SILENCE_SECONDS));
        if (count($silent) < $needed) {
            throw TranscodeSessionLimitException::at($this->limit());
        }
        $own = array_slice($silent, 0, max(0, $needed));
        $slot = $this->slotHolderToEnd($occupied, $own, array_diff_key($locked, self::keyed($own)), $now);
        $targets = self::keyed([...$own, ...array_filter([$slot])]);
        ksort($targets, SORT_STRING);
        foreach ($targets as $target) {
            $this->end($target);
        }
    }

    /**
     * Refuses a start whose tiers cannot fit the user's share even after the user's silent sessions
     * give way, without ending any. A tier the playback already holds is handed back and needs no room.
     *
     * @param list<string> $tiers the quality tiers the start admits
     * @return list<Uuid> the sessions the playback already holds
     * @throws TranscodeSessionLimitException
     */
    public function assertRoomFor(Uuid $userId, Uuid $videoId, string $requestId, array $tiers): array
    {
        return $this->transaction->run(function () use ($userId, $videoId, $requestId, $tiers): array {
            $this->store->lockUser($userId);
            $held = $this->store->heldBy($userId);
            $playback = self::playback($held, $videoId, $requestId);
            $starting = array_diff($tiers, array_map(static fn (HeldTranscodeSession $session): string => $session->tier, $playback));
            if ($starting !== []) {
                $this->ownCandidates($held, $videoId, $requestId, $this->excess($held, count($starting)), $this->clock->now());
            }

            return self::ids($playback);
        });
    }

    /**
     * Ends sessions a refused start created, each in a transaction of its own.
     *
     * @param list<Uuid> $sessionIds
     */
    public function withdraw(array $sessionIds): void
    {
        foreach ($sessionIds as $sessionId) {
            $this->transaction->run(function () use ($sessionId): void {
                $current = $this->store->lock($sessionId);
                if ($current !== null) {
                    $this->end($current);
                }
            });
        }
    }

    /** @param list<HeldTranscodeSession> $held */
    private function excess(array $held, int $starting): int
    {
        return count($held) + $starting - $this->limit();
    }

    /**
     * The user's sessions outside the playback that may give way for the excess: those that have
     * reported nothing for two minutes, oldest sign of life first, or none when nothing is in excess.
     *
     * @param list<HeldTranscodeSession> $held
     * @return list<HeldTranscodeSession>
     * @throws TranscodeSessionLimitException when fewer of them than the excess can give way
     */
    private function ownCandidates(array $held, Uuid $videoId, string $requestId, int $excess, DateTimeImmutable $now): array
    {
        if ($excess <= 0) {
            return [];
        }
        $outside = array_filter($held, static fn (HeldTranscodeSession $session): bool => !$session->belongsTo($videoId, $requestId));
        $silent = self::silentSince($outside, self::before($now, self::OWN_SILENCE_SECONDS));
        if (count($silent) < $excess) {
            throw TranscodeSessionLimitException::at($this->limit());
        }

        return $silent;
    }

    /**
     * Locks the sessions in session id order, as playback controls lock a client's tiers, and reads
     * each again under its locks.
     *
     * @param list<HeldTranscodeSession> $sessions
     * @return array<string, HeldTranscodeSession> those still held, by session id
     */
    private function lockInSessionOrder(array $sessions): array
    {
        $ordered = self::keyed($sessions);
        ksort($ordered, SORT_STRING);

        return array_map(fn (HeldTranscodeSession $session): ?HeldTranscodeSession => $this->store->lock($session->sessionId), $ordered)
            |> array_filter(...);
    }

    /**
     * The slot holder that gives way when the running and waiting executions fill every video slot,
     * counting the slots the own sessions the start ends free: of the other locked sessions, the one
     * that occupies a slot and has reported nothing for seventy minutes with the oldest sign of life.
     *
     * @param list<HeldTranscodeSession> $own
     * @param array<string, HeldTranscodeSession> $locked
     */
    private function slotHolderToEnd(int $occupied, array $own, array $locked, DateTimeImmutable $now): ?HeldTranscodeSession
    {
        $freed = array_filter($own, static fn (HeldTranscodeSession $session): bool => $session->holdsSlot) |> count(...);
        if ($occupied - $freed < $this->videoSlots()) {
            return null;
        }
        $slotHolders = array_filter($locked, static fn (HeldTranscodeSession $session): bool => $session->holdsSlot);

        return self::silentSince($slotHolders, self::before($now, self::SLOT_SILENCE_SECONDS))[0] ?? null;
    }

    /**
     * @param list<HeldTranscodeSession> $held
     * @return list<HeldTranscodeSession> the sessions of the client playback a start continues
     */
    private static function playback(array $held, Uuid $videoId, string $requestId): array
    {
        return array_filter($held, static fn (HeldTranscodeSession $session): bool => $session->belongsTo($videoId, $requestId)) |> array_values(...);
    }

    /**
     * @param array<HeldTranscodeSession> $sessions
     * @return list<HeldTranscodeSession> those whose last sign of life is no later than the instant, oldest first
     */
    private static function silentSince(array $sessions, DateTimeImmutable $instant): array
    {
        $silent = array_filter($sessions, static fn (HeldTranscodeSession $session): bool => $session->lastSignOfLife <= $instant) |> array_values(...);
        usort($silent, static fn (HeldTranscodeSession $a, HeldTranscodeSession $b): int => [$a->lastSignOfLife, $a->sessionId->toString()] <=> [$b->lastSignOfLife, $b->sessionId->toString()]);

        return $silent;
    }

    /**
     * @param array<HeldTranscodeSession> $sessions
     * @return array<string, HeldTranscodeSession> by session id
     */
    private static function keyed(array $sessions): array
    {
        return array_combine(array_map(static fn (HeldTranscodeSession $session): string => $session->sessionId->toString(), $sessions), $sessions);
    }

    /**
     * @param array<HeldTranscodeSession> $sessions
     * @return list<Uuid>
     */
    private static function ids(array $sessions): array
    {
        return array_map(static fn (HeldTranscodeSession $session): Uuid => $session->sessionId, $sessions) |> array_values(...);
    }

    private function end(HeldTranscodeSession $session): void
    {
        if ($session->holdsSlot) {
            // The worker reads the cancellation, stops its encoder and gives up the slot.
            $this->ownership->control($session->jobId, $session->sessionId, new Uuid()->toString(), status: 'cancelled', seek: false);
        }
        $this->store->end($session);
    }

    /**
     * The slots the media worker runs a video execution, encode or copy, in: the video slots, or under
     * remux the shared copy slots. Audio copies share those under remux and are not counted: no
     * session holds them, so none can be ended for their slot, and a start waits for one to finish.
     */
    private function videoSlots(): int
    {
        return count($this->profile->mediaClientIds('video'));
    }

    /** How many sessions one user holds at most. */
    public function limit(): int
    {
        return (int) $this->settings->get(TranscodeSettingDefinitions::SESSIONS_PER_USER);
    }

    private static function before(DateTimeImmutable $now, int $seconds): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d seconds', $seconds));
    }
}
