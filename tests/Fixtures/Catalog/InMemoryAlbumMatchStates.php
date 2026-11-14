<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchState;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Album match states held in memory, for unit tests of the album match queue. It follows the
 * repository contract the PostgreSQL statements implement; AlbumMatchQueueStatementsTest checks
 * those statements against the same rules.
 */
final class InMemoryAlbumMatchStates implements AlbumMatchStateRepositoryInterface
{
    /** @var array<string, AlbumMatchState> by album ID */
    public array $states = [];

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
    public function save(AlbumMatchState $state): void
    {
        $this->has($state);
    }

    #[\Override]
    public function removeByAlbum(Uuid $albumId): void
    {
        unset($this->states[$albumId->toString()]);
    }

    #[\Override]
    public function request(Uuid $albumId, AlbumMatchSource $source, DateTimeImmutable $dueAt): bool
    {
        $existing = $this->stateOf($albumId);
        $waiting = $existing?->status === AlbumMatchStatus::Requested;
        $this->has(new AlbumMatchState(
            albumId: $albumId,
            status: AlbumMatchStatus::Requested,
            source: $waiting && $existing->source === AlbumMatchSource::Admin ? AlbumMatchSource::Admin : $source,
            dueAt: $waiting ? min($existing->dueAt, $dueAt) : $dueAt,
        ));

        return !$waiting;
    }

    #[\Override]
    public function hasRequestsOtherThan(Uuid ...$albumIds): bool
    {
        $excluded = array_map(static fn (Uuid $id): string => $id->toString(), $albumIds);

        return array_any(
            $this->requests(),
            static fn (AlbumMatchState $state): bool => !in_array($state->albumId->toString(), $excluded, true),
        );
    }

    #[\Override]
    public function nextRequestDueAt(): ?DateTimeImmutable
    {
        $dueTimes = array_map(static fn (AlbumMatchState $state): DateTimeImmutable => $state->dueAt, $this->requests());

        return $dueTimes === [] ? null : min($dueTimes);
    }

    #[\Override]
    public function claimOldestDueRequest(DateTimeImmutable $now, DateTimeImmutable $claimedUntil): ?AlbumMatchState
    {
        $due = array_filter($this->requests(), static fn (AlbumMatchState $state): bool => $state->dueAt <= $now);
        usort($due, static fn (AlbumMatchState $one, AlbumMatchState $other): int => [$one->dueAt, $one->albumId->toString()] <=> [$other->dueAt, $other->albumId->toString()]);
        $oldest = array_first($due);
        if ($oldest !== null) {
            $this->has(self::changed($oldest, ['dueAt' => $claimedUntil]));
        }

        return $oldest;
    }

    #[\Override]
    public function releaseClaim(Uuid $albumId, DateTimeImmutable $claimedUntil, DateTimeImmutable $dueAt): void
    {
        $claimed = $this->claimed($albumId, $claimedUntil);
        if ($claimed !== null) {
            $this->has(self::changed($claimed, ['dueAt' => $dueAt]));
        }
    }

    #[\Override]
    public function removeClaimedRequest(Uuid $albumId, DateTimeImmutable $claimedUntil): void
    {
        if ($this->claimed($albumId, $claimedUntil) !== null) {
            $this->removeByAlbum($albumId);
        }
    }

    #[\Override]
    public function markClaimedForReview(
        Uuid $albumId,
        DateTimeImmutable $claimedUntil,
        AlbumMatchReviewReason $reason,
        ?int $bestScore,
        int $checkedSongCount,
        DateTimeImmutable $checkedAt,
    ): bool {
        $claimed = $this->claimed($albumId, $claimedUntil);
        if ($claimed === null) {
            return false;
        }

        $this->has(self::changed($claimed, [
            'status' => AlbumMatchStatus::NeedsReview,
            'dueAt' => $checkedAt,
            'reason' => $reason,
            'bestScore' => $bestScore,
            'checkedSongCount' => $checkedSongCount,
            'checkedAt' => $checkedAt,
        ]));

        return true;
    }

    #[\Override]
    public function removeIngestRequests(): void
    {
        $this->states = array_filter(
            $this->states,
            static fn (AlbumMatchState $state): bool => $state->status !== AlbumMatchStatus::Requested || $state->source !== AlbumMatchSource::Ingest,
        );
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

    /** The album's request while the claim that ends at $claimedUntil holds it. */
    private function claimed(Uuid $albumId, DateTimeImmutable $claimedUntil): ?AlbumMatchState
    {
        $state = $this->stateOf($albumId);

        return $state?->status === AlbumMatchStatus::Requested && $state->dueAt == $claimedUntil ? $state : null;
    }
}
