<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\MusicBrainzMatch;

use App\Catalog\Application\Port\ArtistIdentity;
use App\Catalog\Application\Query\MusicBrainzMatch\CatalogCredit;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchArtistAction;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCreditsRow;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchField;
use App\Catalog\Application\Query\MusicBrainzMatch\PredictedArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtistCredit;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzCreditedArtist;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The rows an album or song apply writes, with what writing them involves: the artist identity of
 * every credit MusicBrainz gives, resolved as an import resolves a tagged artist, the songs written,
 * and the artists whose credits change or who are merged or removed.
 *
 * Each credited artist is the identity of MusicBrainz's credit: the name MusicBrainz credits it
 * under and its MusicBrainz ID, as the comparison predicted it. A credits row's primary credit keeps
 * the item's own credited name, which is not that identity.
 */
#[Exclude]
final readonly class MatchPlan
{
    /**
     * @param array<string, list<ArtistIdentity>> $identities the identity of each proposed credit of each credits row written,
     *                                                        in the row's order, by row key
     * @param array<string, Uuid>                 $songIds    the songs written, by public ID
     */
    private function __construct(
        public MatchTicks $ticks,
        private array $identities,
        public array $songIds,
    ) {
    }

    /**
     * @param array<string, MusicBrainzArtistCredit> $sources the MusicBrainz credit each credits row was compared with, by
     *                                                        row key: the song's public ID, or '' for an album's own credits
     * @param array<string, Uuid>                    $songs   every song the comparison pairs, by public ID
     */
    public static function of(MatchTicks $ticks, array $sources, array $songs): self
    {
        $identities = [];
        foreach ($ticks->credits as $row) {
            $credit = $sources[self::rowKey($row)] ?? throw new \LogicException('A credits row has no MusicBrainz credit.');
            $identities[self::rowKey($row)] = array_map(
                static fn (MusicBrainzCreditedArtist $artist): ArtistIdentity => new ArtistIdentity($artist->name, [$artist->artistId]),
                $credit->artists,
            );
        }

        return new self($ticks, $identities, array_intersect_key($songs, $ticks->songs() |> array_flip(...)));
    }

    /**
     * Every credited artist identity, in the comparison's order: the item's own credits, then its
     * songs' in release order. The comparison predicted the artists in this order.
     *
     * @return list<ArtistIdentity>
     */
    public function identities(): array
    {
        return array_values($this->identities) |> (static fn (array $lists): array => array_merge(...$lists));
    }

    /** The identity of the row's proposed credit at the index. */
    public function identityOf(MatchCreditsRow $row, int $index): ArtistIdentity
    {
        return $this->identities[self::rowKey($row)][$index] ?? throw new \LogicException('A proposed credit has no identity.');
    }

    /**
     * The identity a merge target came from, so a target the match creates is found once resolved.
     */
    public function identityOfArtist(PredictedArtist $artist): ?ArtistIdentity
    {
        foreach ($this->ticks->credits as $row) {
            foreach ($row->proposed as $index => $credit) {
                if ($credit->artist === $artist) {
                    return $this->identityOf($row, $index);
                }
            }
        }

        return null;
    }

    /**
     * Every stored artist the apply may credit, uncredit, merge, merge into or remove: the artists
     * the written credits rows credit now, those they would credit, and the targets of the merges.
     *
     * @return list<Uuid>
     */
    public function artistIds(): array
    {
        $ids = [];
        foreach ($this->ticks->credits as $row) {
            foreach ($row->current as $credit) {
                $ids[$credit->artistId->toString() |> strtolower(...)] = $credit->artistId;
            }
            foreach ($row->proposed as $credit) {
                if ($credit->artist->id !== null) {
                    $ids[$credit->artist->id->toString() |> strtolower(...)] = $credit->artist->id;
                }
            }
        }
        foreach ($this->ticks->actions as $action) {
            if ($action->target?->id !== null) {
                $ids[$action->target->id->toString() |> strtolower(...)] = $action->target->id;
            }
        }

        return array_values($ids);
    }

    /** The internal ID of the artist a merge or removal names; the artist holds a credit the apply replaces. */
    public function artistIdOf(MatchArtistAction $action): Uuid
    {
        foreach ($this->ticks->credits as $row) {
            $credit = array_find($row->current, static fn (CatalogCredit $credit): bool => $credit->artistPublicId === $action->artistPublicId);
            if ($credit !== null) {
                return $credit->artistId;
            }
        }

        throw new \LogicException(sprintf('The artist "%s" holds no credit the match replaces.', $action->artistPublicId));
    }

    /** @return list<string> the genre names the apply files an album under; empty when it leaves the genres */
    public function genres(): array
    {
        $proposed = $this->ticks->field(MatchField::Genres)?->proposed;

        return is_array($proposed) ? $proposed : [];
    }

    /**
     * The locks the apply needs.
     *
     * @param list<ArtistIdentity> $storedArtists the name and MusicBrainz ID of every artist in artistIds(), as stored
     */
    public function locks(array $storedArtists = []): MatchLocks
    {
        return new MatchLocks(
            identities: [...$this->identities(), ...$storedArtists],
            artistIds: $this->artistIds(),
            songIds: array_values($this->songIds),
            genres: $this->genres(),
        );
    }

    private static function rowKey(MatchCreditsRow $row): string
    {
        return $row->songPublicId ?? '';
    }
}
