<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchSnapshots;
use App\Catalog\Application\Service\MusicBrainzMatch\CandidateScorer;
use App\Catalog\Application\Service\MusicBrainzMatch\TrackPairing;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtistCredit;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMedium;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzMediumSummary;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzReleaseSummary;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Unit\Catalog\Application\Service\MusicBrainzMatch\MusicBrainzReleaseFixtures;

/**
 * A library of albums, songs and artists behind stubbed catalog ports, a catalog read fake and a
 * MusicBrainz fake, for the match query handlers.
 */
trait MatchQueryFixtures
{
    use MusicBrainzReleaseFixtures;

    private FakeMusicBrainzLookup $musicBrainz;
    private InMemoryMatchCatalog $catalog;

    /** @var list<Album> */
    private array $albums = [];

    /** @var list<Song> */
    private array $songs = [];

    private function setUpLibrary(): void
    {
        $this->musicBrainz = new FakeMusicBrainzLookup();
        $this->catalog = new InMemoryMatchCatalog();
    }

    private function addAlbum(string $title = self::ALBUM_TITLE, ?int $year = null): Album
    {
        $album = Album::create(new Uuid(), $title, 'studio', year: $year);
        $this->albums[] = $album;

        return $album;
    }

    /**
     * One song per track of the release, tagged with its disc, number and title, 1.5 seconds longer
     * than the release says.
     *
     * @param list<string> $missing "<disc>-<position>" of tracks the album has no song for
     *
     * @return list<Song>
     */
    private function addSongsOf(Album $album, MusicBrainzRelease $release, array $missing = []): array
    {
        $songs = [];
        foreach ($release->media as $medium) {
            foreach ($medium->tracks as $track) {
                $key = $medium->position . '-' . $track->position;
                if (in_array($key, $missing, true)) {
                    continue;
                }
                $songs[] = Song::create(
                    album: $album->getId(),
                    title: $track->title,
                    path: '/music/long-bed/' . $key . '.flac',
                    size: 1_000,
                    mimeType: 'audio/flac',
                    length: $track->lengthMs / 1000 + 1.5,
                    track: $track->position,
                    disc: $medium->position,
                );
            }
        }
        $this->songs = [...$this->songs, ...$songs];

        return $songs;
    }

    private function scorer(): CandidateScorer
    {
        return new CandidateScorer(new TrackPairing());
    }

    private function snapshots(): MatchSnapshots
    {
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByPublicId')->willReturnCallback(
            fn (PublicId $id): ?Album => array_find($this->albums, static fn (Album $album): bool => $album->getPublicId()->toString() === $id->toString()),
        );
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByPublicId')->willReturnCallback(
            fn (PublicId $id): ?Song => array_find($this->songs, static fn (Song $song): bool => $song->getPublicId()->toString() === $id->toString()),
        );
        $songs->method('findByAlbumSortedByTrack')->willReturnCallback(
            fn (Uuid $albumId): array => array_filter($this->songs, static fn (Song $song): bool => $song->getAlbumId()->equals($albumId)) |> array_values(...),
        );

        return new MatchSnapshots($albums, $songs, $this->artistPort(), $this->catalog);
    }

    private function artistPort(): ArtistPortInterface
    {
        $artists = $this->createStub(ArtistPortInterface::class);
        $artists->method('findByPublicId')->willReturnCallback(
            fn (PublicId $id): ?Artist => array_find($this->catalog->artists, static fn (Artist $artist): bool => $artist->getPublicId()->toString() === $id->toString()),
        );
        $artists->method('findByMbid')->willReturnCallback(
            fn (?MusicbrainzId $mbid): ?Artist => $mbid === null
                ? null
                : array_find($this->catalog->artists, static fn (Artist $artist): bool => $artist->getMbid() === $mbid->toString()),
        );

        return $artists;
    }

    /** The release with the named values replaced, such as `date: '2024-01-01'`. */
    private static function releaseWith(MusicBrainzRelease $release, mixed ...$changes): MusicBrainzRelease
    {
        return new MusicBrainzRelease(...[...get_object_vars($release), ...$changes]);
    }

    /** The release as a release search lists it, credited to `$credit` when given. */
    private static function summary(MusicBrainzRelease $release, ?MusicBrainzArtistCredit $credit = null): MusicBrainzReleaseSummary
    {
        return new MusicBrainzReleaseSummary(
            id: $release->id,
            title: $release->title,
            score: 100,
            artistCredit: $credit ?? $release->artistCredit,
            date: $release->date,
            country: $release->country,
            status: $release->status,
            disambiguation: $release->disambiguation,
            barcode: $release->barcode,
            labels: $release->labels,
            media: array_map(static fn (MusicBrainzMedium $medium): MusicBrainzMediumSummary => new MusicBrainzMediumSummary($medium->format, count($medium->tracks)), $release->media),
            trackCount: $release->trackCount(),
            releaseGroup: $release->releaseGroup,
        );
    }

    /** A UUID-shaped MusicBrainz release ID numbered `$number`. */
    private static function releaseId(int $number): string
    {
        return sprintf('0e1ea5e0-0000-4000-8000-%012d', $number);
    }
}
