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
 * then execution rows, those it ends in session id order, then session and job rows. Ending a session
 * takes its execution row without its video row, so a start never waits for a second video row while
 * it holds one.
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
        $own = $this->ownSessionsToEnd($held, $videoId, $requestId, 1, $now);
        $slot = $this->slotHolderToEnd($held, $own, $videoId, $requestId, $now);
        $targets = [...$own, ...array_filter([$slot])];
        // Execution rows in session id order, as playback controls lock a client's tiers.
        usort($targets, static fn (HeldTranscodeSession $a, HeldTranscodeSession $b): int => strcmp($a->sessionId->toString(), $b->sessionId->toString()));
        foreach ($targets as $target) {
            $required = $target !== $slot;
            $silentSince = self::before($now, $required ? self::OWN_SILENCE_SECONDS : self::SLOT_SILENCE_SECONDS);
            $current = $this->store->lock($target->sessionId);
            // A session that ended since it was read has freed its room already.
            if ($current === null) {
                continue;
            }
            if ($current->lastSignOfLife <= $silentSince) {
                $this->end($current);
            } elseif ($required) {
                throw TranscodeSessionLimitException::at($this->limit());
            }
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
            $playback = array_filter($held, static fn (HeldTranscodeSession $session): bool => $session->belongsTo($videoId, $requestId));
            $starting = array_diff($tiers, array_map(static fn (HeldTranscodeSession $session): string => $session->tier, $playback));
            if ($starting !== []) {
                $this->ownSessionsToEnd($held, $videoId, $requestId, count($starting), $this->clock->now());
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

    /**
     * The user's sessions that must end for the given number of new ones to fit, oldest sign of life first.
     *
     * @param list<HeldTranscodeSession> $held oldest sign of life first
     * @return list<HeldTranscodeSession>
     * @throws TranscodeSessionLimitException
     */
    private function ownSessionsToEnd(array $held, Uuid $videoId, string $requestId, int $starting, DateTimeImmutable $now): array
    {
        $limit = $this->limit();
        $excess = count($held) + $starting - $limit;
        if ($excess <= 0) {
            return [];
        }
        $silentSince = self::before($now, self::OWN_SILENCE_SECONDS);
        $silent = array_values(array_filter(
            $held,
            static fn (HeldTranscodeSession $session): bool => !$session->belongsTo($videoId, $requestId) && $session->lastSignOfLife <= $silentSince,
        ));
        if (count($silent) < $excess) {
            throw TranscodeSessionLimitException::at($limit);
        }

        return array_slice($silent, 0, $excess);
    }

    /**
     * The slot holder that gives way when the running and waiting executions fill every video slot,
     * counting the slots the user's own ended sessions free.
     *
     * @param list<HeldTranscodeSession> $held
     * @param list<HeldTranscodeSession> $own
     */
    private function slotHolderToEnd(array $held, array $own, Uuid $videoId, string $requestId, DateTimeImmutable $now): ?HeldTranscodeSession
    {
        $freed = array_filter($own, static fn (HeldTranscodeSession $session): bool => $session->holdsSlot) |> count(...);
        if ($this->store->occupiedVideoSlots() - $freed < $this->videoSlots()) {
            return null;
        }
        $playback = array_filter($held, static fn (HeldTranscodeSession $session): bool => $session->belongsTo($videoId, $requestId));

        return $this->store->oldestSlotHolder(self::before($now, self::SLOT_SILENCE_SECONDS), self::ids([...$own, ...$playback]));
    }

    /**
     * @param array<HeldTranscodeSession> $sessions
     * @return list<Uuid>
     */
    private static function ids(array $sessions): array
    {
        return array_values(array_map(static fn (HeldTranscodeSession $session): Uuid => $session->sessionId, $sessions));
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

    private function limit(): int
    {
        return (int) $this->settings->get(TranscodeSettingDefinitions::SESSIONS_PER_USER);
    }

    private static function before(DateTimeImmutable $now, int $seconds): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d seconds', $seconds));
    }
}
