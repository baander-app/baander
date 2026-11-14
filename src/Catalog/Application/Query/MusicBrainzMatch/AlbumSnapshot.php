<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

use App\Catalog\Application\Service\MusicBrainzMatch\LocalAlbum;
use App\Catalog\Application\Service\MusicBrainzMatch\LocalTrack;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Song;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** An album with its songs, credits and genres, as read once for scoring and comparing it. */
#[Exclude]
final readonly class AlbumSnapshot
{
    /**
     * @param list<Song>                         $songs       sorted by disc and track
     * @param array<string, list<CatalogCredit>> $songCredits every credit of each song, by song ID, as MatchCatalogReadInterface::songCredits() orders them
     * @param list<CatalogCredit>                $credits     every credit of the album
     * @param list<string>                       $genres      the album's genre names
     */
    public function __construct(
        public Album $album,
        public array $songs,
        public array $songCredits,
        public array $credits,
        public array $genres,
    ) {
    }

    /** The name the album's first primary credit shows; null for an album without one, such as a compilation. */
    public function primaryArtistName(): ?string
    {
        return array_find($this->credits, static fn (CatalogCredit $credit): bool => $credit->isPrimary())?->creditedName;
    }

    /**
     * The album as matching scores it. Each track's ID is its song's ID, so a track pair names the
     * song it pairs.
     *
     * @param string|null $title  the title to score against instead of the album's own
     * @param string|null $artist the artist to score against instead of the album's primary artist
     */
    public function localAlbum(?string $title = null, ?string $artist = null): LocalAlbum
    {
        return new LocalAlbum(
            title: $title ?? $this->album->getTitle(),
            artistName: $artist ?? $this->primaryArtistName() ?? '',
            tracks: array_map($this->localTrack(...), $this->songs),
        );
    }

    /** @return list<CatalogCredit> every credit the song holds */
    public function creditsOf(Song $song): array
    {
        return $this->songCredits[$song->getId()->toString()] ?? [];
    }

    public function song(string $songId): ?Song
    {
        return array_find($this->songs, static fn (Song $song): bool => $song->getId()->toString() === $songId);
    }

    private function localTrack(Song $song): LocalTrack
    {
        return new LocalTrack(
            id: $song->getId()->toString(),
            title: $song->getTitle(),
            disc: $song->getDisc(),
            position: $song->getTrack(),
            lengthSeconds: $song->getLength(),
            artistNames: $this->creditsOf($song) |> SongSnapshot::performerNames(...),
            recordingId: $song->getMbid(),
        );
    }
}
