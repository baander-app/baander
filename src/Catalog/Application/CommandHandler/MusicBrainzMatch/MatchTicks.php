<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\MusicBrainzMatch;

use App\Catalog\Application\Command\MusicBrainzMatch\MatchSelection;
use App\Catalog\Application\Command\MusicBrainzMatch\SeenCredit;
use App\Catalog\Application\Query\MusicBrainzMatch\CatalogCredit;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchArtistAction;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchComparison;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCreditsRow;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchFieldRow;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchTrack;
use App\Shared\Application\Exception\ConflictException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The rows of a comparison an apply writes: an admin's ticked rows, or for an automatic apply the
 * rows it fills. A row that would change nothing is left out, and a merge or removal is kept only
 * while every credits row it depends on is written.
 */
#[Exclude]
final readonly class MatchTicks
{
    public const string STALE_REASON = 'match_stale';

    /**
     * @param list<MatchFieldRow>                $fields     the item's own field rows
     * @param array<string, list<MatchFieldRow>> $songFields an album's song rows, by song public ID
     * @param list<MatchCreditsRow>              $credits    in comparison order: the item's own credits, then its songs' in release order
     * @param list<MatchArtistAction>            $actions
     */
    private function __construct(
        public array $fields,
        public array $songFields,
        public array $credits,
        public array $actions,
    ) {
    }

    /**
     * The rows a match without an admin writes: every MusicBrainz ID, field and cover that is empty
     * and unlocked, every credits row that changes, and every merge and removal those credits cause.
     * A field that holds a value is never written, whatever MusicBrainz gives.
     */
    public static function automatic(MatchComparison $comparison): self
    {
        $fills = static fn (array $rows): array => array_filter($rows, self::fills(...)) |> array_values(...);
        $songFields = [];
        foreach (self::pairedTracks($comparison) as $publicId => $track) {
            $rows = $fills($track->fields);
            if ($rows !== []) {
                $songFields[$publicId] = $rows;
            }
        }
        $credits = array_filter(self::creditRows($comparison), static fn (MatchCreditsRow $row): bool => $row->changes()) |> array_values(...);

        return new self($fills($comparison->fields), $songFields, $credits, self::standing($comparison->artistActions, $credits));
    }

    /**
     * The rows the admin ticked, once each shows the current value the admin saw. A merge or removal
     * the comparison lists now must have been shown to the admin, ticked or not.
     *
     * @throws ConflictException when a ticked row is gone, locked or shows another current value, or
     *                           when the comparison lists a merge or removal the admin did not see
     */
    public static function manual(MatchComparison $comparison, MatchSelection $selection): self
    {
        $fields = [];
        $songFields = [];
        foreach ($selection->fields as $seen) {
            $row = $seen->songPublicId === null
                ? $comparison->field($seen->field)
                : self::songRow($comparison, $seen->songPublicId, $seen->field);
            if ($row === null || $row->locked || !self::sameValue($row->current, $seen->current)) {
                throw sprintf('The %s changed since the comparison.', $seen->field->value) |> self::stale(...);
            }
            if (!$row->changes()) {
                continue;
            }
            if ($seen->songPublicId === null) {
                $fields[] = $row;
            } else {
                $songFields[$seen->songPublicId][] = $row;
            }
        }

        $ticked = [];
        foreach ($selection->credits as $seen) {
            $row = self::creditsRow($comparison, $seen->songPublicId);
            if ($row === null || !self::sameCredits($row->current, $seen->current)) {
                throw self::stale('The artist credits changed since the comparison.');
            }
            $ticked[self::rowKey($row)] = true;
        }
        $credits = array_filter(
            self::creditRows($comparison),
            static fn (MatchCreditsRow $row): bool => isset($ticked[self::rowKey($row)]) && $row->changes(),
        ) |> array_values(...);

        $actions = [];
        foreach (self::standing($comparison->artistActions, $credits) as $action) {
            if (in_array($action->artistPublicId, $selection->artistActions, true)) {
                $actions[] = $action;
            } elseif (!in_array($action->artistPublicId, $selection->keptArtists, true)) {
                throw sprintf('The match would now merge or remove the artist "%s".', $action->artistName) |> self::stale(...);
            }
        }

        return new self($fields, $songFields, $credits, $actions);
    }

    /** The item's own row of the field when the apply writes it. */
    public function field(MatchField $field): ?MatchFieldRow
    {
        return array_find($this->fields, static fn (MatchFieldRow $row): bool => $row->field === $field);
    }

    /** The credits row of the song (null for the item's own credits) when the apply writes it. */
    public function creditsOf(?string $songPublicId): ?MatchCreditsRow
    {
        return array_find($this->credits, static fn (MatchCreditsRow $row): bool => $row->songPublicId === $songPublicId);
    }

    /** @return list<string> the public IDs of the songs the apply writes fields or credits of */
    public function songs(): array
    {
        return [...array_keys($this->songFields), ...array_column($this->credits, 'songPublicId')]
            |> array_filter(...)
            |> array_unique(...)
            |> array_values(...);
    }

    public static function stale(string $message): ConflictException
    {
        return new ConflictException($message . ' Compare the match again.', ['reason' => self::STALE_REASON]);
    }

    /** Whether a match without an admin writes the row: it changes an empty, unlocked field. */
    private static function fills(MatchFieldRow $row): bool
    {
        return !$row->locked && $row->current === null && $row->changes();
    }

    /**
     * The merges and removals whose credits rows are all written.
     *
     * @param list<MatchArtistAction> $actions
     * @param list<MatchCreditsRow>   $credits the rows written
     *
     * @return list<MatchArtistAction>
     */
    private static function standing(array $actions, array $credits): array
    {
        $songs = array_column($credits, 'songPublicId') |> array_filter(...);
        $albumCredits = array_any($credits, static fn (MatchCreditsRow $row): bool => $row->isAlbumCredits());

        return array_filter(
            $actions,
            static fn (MatchArtistAction $action): bool => (!$action->requiresAlbumCredits || $albumCredits)
                && array_all($action->requiredSongs, static fn (string $song): bool => in_array($song, $songs, true)),
        ) |> array_values(...);
    }

    /**
     * Every credits row of the comparison, in its order.
     *
     * @return list<MatchCreditsRow>
     */
    private static function creditRows(MatchComparison $comparison): array
    {
        return [$comparison->credits, ...array_column(self::pairedTracks($comparison), 'credits')]
            |> array_filter(...)
            |> array_values(...);
    }

    /** @return array<string, MatchTrack> the paired tracks in release order, by song public ID */
    private static function pairedTracks(MatchComparison $comparison): array
    {
        $tracks = [];
        foreach ($comparison->tracks as $track) {
            if ($track->isPaired() && $track->song !== null) {
                $tracks[$track->song->publicId] = $track;
            }
        }

        return $tracks;
    }

    private static function songRow(MatchComparison $comparison, string $songPublicId, MatchField $field): ?MatchFieldRow
    {
        return array_find(
            self::pairedTracks($comparison)[$songPublicId]->fields ?? [],
            static fn (MatchFieldRow $row): bool => $row->field === $field,
        );
    }

    private static function creditsRow(MatchComparison $comparison, ?string $songPublicId): ?MatchCreditsRow
    {
        return array_find(
            self::creditRows($comparison),
            static fn (MatchCreditsRow $row): bool => $songPublicId === null ? $row === $comparison->credits : $row->songPublicId === $songPublicId,
        );
    }

    private static function rowKey(MatchCreditsRow $row): string
    {
        return $row->songPublicId ?? '';
    }

    /**
     * @param string|int|bool|list<string>|null $current the value the row shows now
     * @param string|int|bool|list<string>|null $seen    the value the admin saw
     */
    private static function sameValue(string|int|bool|array|null $current, string|int|bool|array|null $seen): bool
    {
        $seen = $seen === [] || (is_string($seen) && trim($seen) === '') ? null : $seen;
        if (is_bool($current) || is_bool($seen)) {
            return $current === $seen;
        }
        if (is_array($current) || is_array($seen)) {
            return is_array($current) && is_array($seen) && self::folded($current) === self::folded($seen);
        }

        return $current === null || $seen === null ? $current === $seen : (string) $current === (string) $seen;
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function folded(array $names): array
    {
        $folded = array_map(mb_strtolower(...), $names) |> array_unique(...);
        sort($folded);

        return $folded;
    }

    /**
     * @param list<CatalogCredit> $current
     * @param list<SeenCredit>    $seen
     */
    private static function sameCredits(array $current, array $seen): bool
    {
        $now = array_map(static fn (CatalogCredit $credit): string => self::creditKey($credit->artistPublicId, $credit->role, $credit->creditedName), $current);
        $then = array_map(static fn (SeenCredit $credit): string => self::creditKey($credit->artistPublicId, $credit->role, $credit->creditedName), $seen);
        sort($now);
        sort($then);

        return $now === $then;
    }

    private static function creditKey(string $artistPublicId, ?string $role, string $creditedName): string
    {
        return implode("\0", [$artistPublicId, $role ?? '', $creditedName]);
    }
}
