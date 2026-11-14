<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\MusicBrainzMatch;

use App\Catalog\Application\Port\ArtistIdentity;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * What an apply locks, in the order every catalog writer takes its locks: the import's advisory
 * keys of the artists and genres it names, then the rows of the artists it may credit, merge,
 * merge into or remove, then the rows of the songs it writes. The apply works the set out from a
 * comparison read before its transaction, takes it, and compares again under the locks: when that
 * comparison needs anything the set left out, the item changed meanwhile and the apply writes
 * nothing, since taking a lock out of order could deadlock with another writer.
 */
#[Exclude]
final readonly class MatchLocks
{
    /**
     * @param list<ArtistIdentity> $identities every artist identity whose name and MusicBrainz ID keys the apply takes
     * @param list<Uuid>           $artistIds  the artists whose rows the apply locks
     * @param list<Uuid>           $songIds    the songs whose rows the apply locks
     * @param list<string>         $genres     the genre names whose keys the apply takes
     */
    public function __construct(
        public array $identities,
        public array $artistIds = [],
        public array $songIds = [],
        public array $genres = [],
    ) {
    }

    /** Whether these locks hold every key and row the needed set names. */
    public function covers(self $needed): bool
    {
        return self::within(self::names($needed->identities), self::names($this->identities))
            && self::within(self::musicbrainzIds($needed->identities), self::musicbrainzIds($this->identities))
            && self::within(self::ids($needed->artistIds), self::ids($this->artistIds))
            && self::within(self::ids($needed->songIds), self::ids($this->songIds))
            && self::within($needed->genres, $this->genres);
    }

    /**
     * @param list<string> $needed
     * @param list<string> $held
     */
    private static function within(array $needed, array $held): bool
    {
        return array_diff($needed, $held) === [];
    }

    /**
     * @param list<ArtistIdentity> $identities
     *
     * @return list<string>
     */
    private static function names(array $identities): array
    {
        return array_map(static fn (ArtistIdentity $identity): string => $identity->name, $identities);
    }

    /**
     * @param list<ArtistIdentity> $identities
     *
     * @return list<string>
     */
    private static function musicbrainzIds(array $identities): array
    {
        return array_map(static fn (ArtistIdentity $identity): ?string => $identity->musicbrainzId?->toString(), $identities)
            |> array_filter(...)
            |> array_values(...);
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_map(static fn (Uuid $id): string => $id->toString() |> strtolower(...), $ids);
    }
}
