<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchState;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Shared\Domain\Model\Uuid;
use Closure;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Album match states held in memory, for unit tests of the album match queue. It follows the
 * repository contract the PostgreSQL statements implement; AlbumMatchQueueStatementsTest checks
 * those statements against the same rules. Its own clock stands in for the database clock, which
 * sets a claim's expiry and decides whether the claim still lasts.
 */
final class InMemoryAlbumMatchStates implements AlbumMatchStateRepositoryInterface
{
    /** @var array<string, AlbumMatchState> by album ID */
    public array $states = [];

    /** @var array<string, array{id: Uuid, expiresAt: DateTimeImmutable, nextDueAt: ?DateTimeImmutable}> each claimed album's claim and the due time of a request that waits beside it, by album ID */
    private array $claims = [];

    public function __construct(
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function has(AlbumMatchState ...$states): self
    {
        foreach ($states as $state) {
            $this->states[$state->albumId->toString()] = $state;
        }

        return $this;
    }

    public function stateOf(Uuid $albumId): ?AlbumMatchState
    {
        return $this->states[$albumId->toString()] ?? null;
    }

    #[\Override]
    public function findByAlbum(Uuid $albumId): ?AlbumMatchState
    {
        return $this->stateOf($albumId);
    }

    #[\Override]
    public function removeByAlbum(Uuid $albumId): void
    {
        unset($this->states[$albumId->toString()], $this->claims[$albumId->toString()]);
    }

    #[\Override]
    public function request(Uuid $albumId, AlbumMatchSource $source, DateTimeImmutable $dueAt): bool
    {
        $id = $albumId->toString();
        $existing = $this->stateOf($albumId);
        $waiting = $existing?->status === AlbumMatchStatus::Requested;
        $source = $waiting && $existing->source === AlbumMatchSource::Admin ? AlbumMatchSource::Admin : $source;
        $claim = $this->liveClaim($albumId);
        if ($existing !== null && $claim !== null) {
            $this->claims[$id]['nextDueAt'] = min($claim['nextDueAt'] ?? $dueAt, $dueAt);
            $this->has(self::changed($existing, ['source' => $source]));

            return false;
        }

        unset($this->claims[$id]);
        $this->has(new AlbumMatchState($albumId, AlbumMatchStatus::Requested, $source, $waiting ? min($existing->dueAt, $dueAt) : $dueAt));

        return !$waiting || $dueAt < $existing->dueAt;
    }

    #[\Override]
    public function hasRequestsDueBy(DateTimeImmutable $dueAt, Uuid ...$albumIds): bool
    {
        $excluded = array_map(static fn (Uuid $id): string => $id->toString(), $albumIds);

        return array_any(
            $this->requests(),
            fn (AlbumMatchState $state): bool => $this->claimableFrom($state) <= $dueAt && !in_array($state->albumId->toString(), $excluded, true),
        );
    }

    #[\Override]
    public function nextRequestDueAt(): ?DateTimeImmutable
    {
        $times = array_map($this->claimableFrom(...), $this->requests());

        return $times === [] ? null : min($times);
    }

    #[\Override]
    public function claimOldestDueRequest(DateTimeImmutable $now, Uuid $claimId, int $leaseSeconds): ?AlbumMatchState
    {
        $due = array_filter(
            $this->requests(),
            fn (AlbumMatchState $state): bool => $state->dueAt <= $now && $this->liveClaim($state->albumId) === null,
        );
        usort($due, static fn (AlbumMatchState $one, AlbumMatchState $other): int => [$one->dueAt, $one->albumId->toString()] <=> [$other->dueAt, $other->albumId->toString()]);
        $oldest = array_first($due);
        if ($oldest !== null) {
            $this->claims[$oldest->albumId->toString()] = [
                'id' => $claimId,
                'expiresAt' => $this->clock->now()->add(new \DateInterval('PT' . $leaseSeconds . 'S')),
                'nextDueAt' => null,
            ];
        }

        return $oldest;
    }

    #[\Override]
    public function removeClaimedRequest(Uuid $albumId, Uuid $claimId): ?AlbumMatchState
    {
        return $this->endClaim($albumId, $claimId, static fn (): null => null);
    }

    #[\Override]
    public function markClaimedForReview(
        Uuid $albumId,
        Uuid $claimId,
        AlbumMatchReviewReason $reason,
        ?int $bestScore,
        int $checkedSongCount,
        DateTimeImmutable $checkedAt,
    ): ?AlbumMatchState {
        return $this->endClaim($albumId, $claimId, static fn (AlbumMatchState $claimed): AlbumMatchState => self::changed($claimed, [
            'status' => AlbumMatchStatus::NeedsReview,
            'dueAt' => $checkedAt,
            'reason' => $reason,
            'bestScore' => $bestScore,
            'checkedSongCount' => $checkedSongCount,
            'checkedAt' => $checkedAt,
        ]));
    }

    #[\Override]
    public function postponeClaimedRequest(Uuid $albumId, Uuid $claimId, DateTimeImmutable $dueAt): ?AlbumMatchState
    {
        return $this->endClaim($albumId, $claimId, static fn (AlbumMatchState $claimed): AlbumMatchState => self::changed($claimed, ['dueAt' => $dueAt]));
    }

    #[\Override]
    public function releaseClaim(Uuid $albumId, Uuid $claimId): ?AlbumMatchState
    {
        $claimed = $this->heldBy($albumId, $claimId);
        if ($claimed !== null) {
            unset($this->claims[$albumId->toString()]);
        }

        return $claimed;
    }

    #[\Override]
    public function removeIngestRequests(): void
    {
        $this->states = array_filter(
            $this->states,
            static fn (AlbumMatchState $state): bool => $state->status !== AlbumMatchStatus::Requested || $state->source !== AlbumMatchSource::Ingest,
        );
        $this->claims = array_intersect_key($this->claims, $this->states);
    }

    /** @return list<AlbumMatchState> */
    private function requests(): array
    {
        return array_filter($this->states, static fn (AlbumMatchState $state): bool => $state->status === AlbumMatchStatus::Requested)
            |> array_values(...);
    }

    /**
     * The state with some of its values replaced. A readonly object cannot be cloned with new
     * values from outside its class.
     *
     * @param array<string, mixed> $changes by constructor parameter name
     */
    private static function changed(AlbumMatchState $state, array $changes): AlbumMatchState
    {
        return new AlbumMatchState(...[...get_object_vars($state), ...$changes]);
    }

    /**
     * The claim on the album while it lasts by this fake's clock.
     *
     * @return array{id: Uuid, expiresAt: DateTimeImmutable, nextDueAt: ?DateTimeImmutable}|null
     */
    private function liveClaim(Uuid $albumId): ?array
    {
        $claim = $this->claims[$albumId->toString()] ?? null;

        return $claim !== null && $claim['expiresAt'] > $this->clock->now() ? $claim : null;
    }

    /** When a run can take the request: at its due time, or when the live claim on it expires. */
    private function claimableFrom(AlbumMatchState $request): DateTimeImmutable
    {
        return $this->liveClaim($request->albumId)['expiresAt'] ?? $request->dueAt;
    }

    /** The album's request while the claim holds it; a lapsed claim holds it until a run or a request takes it. */
    private function heldBy(Uuid $albumId, Uuid $claimId): ?AlbumMatchState
    {
        $claim = $this->claims[$albumId->toString()] ?? null;

        return $claim !== null && $claim['id']->equals($claimId) ? $this->stateOf($albumId) : null;
    }

    /**
     * Ends the claim on the album it still holds. With a request waiting beside the claim, the
     * album waits for a match again from that request's due time; otherwise $outcome gives the
     * state the album is left in, or null to remove its request.
     *
     * @param Closure(AlbumMatchState): ?AlbumMatchState $outcome
     *
     * @return AlbumMatchState|null the state the album is left in; null when its request was removed or the claim no longer held it
     */
    private function endClaim(Uuid $albumId, Uuid $claimId, Closure $outcome): ?AlbumMatchState
    {
        $claimed = $this->heldBy($albumId, $claimId);
        if ($claimed === null) {
            return null;
        }

        $id = $albumId->toString();
        $nextDueAt = $this->claims[$id]['nextDueAt'];
        $left = $nextDueAt === null ? $outcome($claimed) : self::changed($claimed, ['dueAt' => $nextDueAt]);
        unset($this->states[$id], $this->claims[$id]);

        return $left === null ? null : $this->has($left)->stateOf($albumId);
    }
}
