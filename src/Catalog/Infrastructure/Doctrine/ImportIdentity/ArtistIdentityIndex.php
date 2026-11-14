<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\ImportIdentity;

use App\Catalog\Application\Port\ArtistIdentity;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Decides which artist each identity of an import batch names, one identity at a time, among the
 * stored artists the batch read and the artists it has created or given an ID so far:
 *
 * - With a MusicBrainz ID: the artist holding the ID, whatever its name; else the oldest artist
 *   without an ID whose name equals the tagged name, which takes on the ID; else a new artist
 *   with the name and the ID.
 * - Without one: among the artists whose name equals the tagged name, the oldest without an ID,
 *   else the oldest; else a new artist with the name.
 *
 * Oldest means earliest created, then lowest ID, as PostgreSQL orders UUIDs. An artist the batch
 * creates is newer than every stored one. Deciding identities in turn against what earlier ones
 * created or changed makes aliases of one ID, and a name seen with and without an ID, converge.
 */
#[Exclude]
final class ArtistIdentityIndex
{
    /** @var list<ArtistIdentityCandidate> oldest first */
    private array $artists;

    /** @param list<ArtistIdentityCandidate> $stored stored artists, in any order */
    public function __construct(array $stored)
    {
        usort($stored, self::older(...));
        $this->artists = $stored;
    }

    /** Returns the artist the identity names, after creating it or giving it the identity's ID when the rules say so. */
    public function resolve(ArtistIdentity $identity): ArtistIdentityCandidate
    {
        $named = array_values(array_filter(
            $this->artists,
            static fn (ArtistIdentityCandidate $artist): bool => $artist->name === $identity->name,
        ));
        $namedWithoutId = array_find($named, static fn (ArtistIdentityCandidate $artist): bool => $artist->mbid === null);

        $mbid = $identity->musicbrainzId?->toString();
        if ($mbid === null) {
            return $namedWithoutId ?? $named[0] ?? $this->create($identity->name, null);
        }

        $holder = array_find($this->artists, static fn (ArtistIdentityCandidate $artist): bool => $artist->mbid === $mbid);
        if ($holder !== null) {
            return $holder;
        }
        if ($namedWithoutId !== null) {
            $namedWithoutId->mbid = $mbid;

            return $namedWithoutId;
        }

        return $this->create($identity->name, $mbid);
    }

    /** @return list<ArtistIdentityCandidate> the stored artists oldest first, then those the batch created in order */
    public function artists(): array
    {
        return $this->artists;
    }

    private function create(string $name, ?string $mbid): ArtistIdentityCandidate
    {
        $artist = new ArtistIdentityCandidate(new Uuid(), $name, $mbid, createdAt: null);
        $this->artists[] = $artist;

        return $artist;
    }

    private static function older(ArtistIdentityCandidate $one, ArtistIdentityCandidate $other): int
    {
        // Lower-case hexadecimal text sorts as the UUID bytes do.
        return $one->createdAt <=> $other->createdAt
            ?: strcmp(strtolower($one->id->toString()), strtolower($other->id->toString()));
    }
}
