<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch;

use App\Catalog\Application\Query\MusicBrainzMatch\CatalogCredit;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCatalogReadInterface;
use App\Catalog\Application\Query\MusicBrainzMatch\PredictedArtist;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Shared\Domain\Model\Uuid;

/**
 * The catalog reads of a comparison over artists, credits and genres a test set up. It predicts
 * artists by the import's rules: the ID holder, else the oldest artist without an ID of that name,
 * which takes on the ID, else a new artist.
 */
final class InMemoryMatchCatalog implements MatchCatalogReadInterface
{
    /** @var list<Artist> oldest first */
    public array $artists = [];

    /** @var array<string, list<CatalogCredit>> by song ID */
    public array $songCredits = [];

    /** @var array<string, list<CatalogCredit>> by album ID */
    public array $albumCredits = [];

    /** @var array<string, list<string>> by album ID */
    public array $albumGenres = [];

    /** @var list<string> the IDs of artists that hold a credit outside any comparison */
    public array $creditedElsewhere = [];

    public function artist(string $name, ?string $mbid = null): Artist
    {
        $artist = Artist::create($name, mbid: $mbid);
        $this->artists[] = $artist;

        return $artist;
    }

    public function creditSong(Song $song, Artist $artist, ArtistRole $role, ?string $creditedName = null): void
    {
        $this->songCredits[$song->getId()->toString()][] = self::credit($artist, $role, $creditedName);
    }

    public function creditAlbum(Album $album, Artist $artist, ArtistRole $role, ?string $creditedName = null): void
    {
        $this->albumCredits[$album->getId()->toString()][] = self::credit($artist, $role, $creditedName);
    }

    #[\Override]
    public function songCredits(array $songIds): array
    {
        $wanted = array_map(static fn (Uuid $id): string => $id->toString(), $songIds);

        return array_filter($this->songCredits, static fn (string $songId): bool => in_array($songId, $wanted, true), ARRAY_FILTER_USE_KEY);
    }

    #[\Override]
    public function albumCredits(Uuid $albumId): array
    {
        return $this->albumCredits[$albumId->toString()] ?? [];
    }

    #[\Override]
    public function albumGenres(Uuid $albumId): array
    {
        return $this->albumGenres[$albumId->toString()] ?? [];
    }

    #[\Override]
    public function predictArtists(array $identities): array
    {
        /** @var array<string, string> $takenIds the MusicBrainz ID each stored artist takes on, by artist ID */
        $takenIds = [];
        /** @var array<string, PredictedArtist> $created by MusicBrainz ID, else by name */
        $created = [];
        $predicted = [];
        foreach ($identities as $identity) {
            $mbid = $identity->musicbrainzId?->toString();
            $idOf = static fn (Artist $artist): ?string => $takenIds[$artist->getId()->toString()] ?? $artist->getMbid();
            $stored = ($mbid === null ? null : array_find($this->artists, static fn (Artist $artist): bool => $idOf($artist) === $mbid))
                ?? array_find($this->artists, static fn (Artist $artist): bool => $artist->getName() === $identity->name && $idOf($artist) === null);
            if ($stored === null) {
                $predicted[$identity->key()] = $created[$mbid ?? $identity->name] ??= PredictedArtist::created($identity->name);
                continue;
            }
            $takesId = $mbid !== null && $stored->getMbid() === null;
            if ($takesId) {
                $takenIds[$stored->getId()->toString()] = $mbid;
            }
            $predicted[$identity->key()] = new PredictedArtist($stored->getId(), $stored->getPublicId()->toString(), $stored->getName(), $takesId);
        }

        return $predicted;
    }

    #[\Override]
    public function artistsCreditedElsewhere(array $artistIds, array $songIds, array $albumIds): array
    {
        return array_map(static fn (Uuid $id): string => $id->toString(), $artistIds)
            |> (fn (array $ids): array => array_intersect($ids, $this->creditedElsewhere))
            |> array_values(...);
    }

    private static function credit(Artist $artist, ArtistRole $role, ?string $creditedName): CatalogCredit
    {
        return new CatalogCredit($artist->getId(), $artist->getPublicId()->toString(), $artist->getName(), $role->value, $creditedName ?? $artist->getName());
    }
}
