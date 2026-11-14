<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use App\Catalog\Application\Port\ArtistIdentity;
use App\Shared\Domain\Model\Uuid;

/**
 * The catalog reads a MusicBrainz comparison needs besides the album, song and artist ports.
 * Every method reads committed rows, takes no lock and changes nothing.
 */
interface MatchCatalogReadInterface
{
    /**
     * The artist credits of these songs, each song's credits primary first, then by credited name
     * and artist ID, so a song's first primary credit names the artist text the song shows.
     *
     * @param list<Uuid> $songIds
     *
     * @return array<string, list<CatalogCredit>> by song ID; a song without credits is absent
     */
    public function songCredits(array $songIds): array;

    /** @return list<CatalogCredit> the album's artist credits, ordered as songCredits() orders a song's */
    public function albumCredits(Uuid $albumId): array;

    /** @return list<string> the names of the genres the album is filed under, sorted by name */
    public function albumGenres(Uuid $albumId): array;

    /**
     * The local artist each identity names, decided as ArtistIdentityResolverInterface::resolve()
     * would decide it now and in the order given: the artist holding the identity's MusicBrainz ID,
     * else the oldest artist without an ID whose name equals the identity's name, which would take
     * on the ID, else a new artist. Locks and writes nothing.
     *
     * @param list<ArtistIdentity> $identities
     *
     * @return array<string, PredictedArtist> keyed by ArtistIdentity::key()
     */
    public function predictArtists(array $identities): array;

    /**
     * Which of these artists hold a credit outside the given songs and albums: a song credit on
     * any other song, or an album credit on any other album, in any library.
     *
     * @param list<Uuid> $artistIds
     * @param list<Uuid> $songIds
     * @param list<Uuid> $albumIds
     *
     * @return list<string> the IDs of those artists
     */
    public function artistsCreditedElsewhere(array $artistIds, array $songIds, array $albumIds): array;
}
