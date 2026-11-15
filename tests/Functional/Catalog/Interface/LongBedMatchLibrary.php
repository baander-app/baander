<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface;

use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Application\Port\MusicBrainzLookupInterface;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Unit\Catalog\Application\CommandHandler\MusicBrainzMatch\CoverServingLookup;
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchQueryFixtures;

/**
 * "F-1 Trillion: Long Bed" in PostgreSQL for the MusicBrainz match routes and commands, with
 * MusicBrainz answered by a fake in the container that knows the release the album fits, and the
 * Cover Art Archive by what a test sets for a release's front cover.
 *
 * The album (2023, with a cover) holds three songs credited to Post Malone; its second song is a
 * duet tagged "Post Malone, Luke Combs" and credited to an artist of that name. The release
 * credits the duet to Post Malone and Luke Combs.
 */
trait LongBedMatchLibrary
{
    use MatchQueryFixtures;

    private AlbumEntity $album;
    private ImageEntity $cover;
    private ArtistEntity $postMalone;
    private ArtistEntity $duetArtist;

    /** @var list<SongEntity> in track order */
    private array $longBedSongs = [];

    private MusicBrainzRelease $release;

    private CoverServingLookup $lookup;

    private function storeLongBed(): void
    {
        $this->setUpLibrary();
        $this->lookup = new CoverServingLookup($this->musicBrainz);
        static::getContainer()->set(MusicBrainzLookupInterface::class, $this->lookup);
        $this->release = self::releaseWith(
            self::release(self::releaseId(1), [3], ['1-2' => ['artistCredit' => self::credit(self::ALBUM_ARTIST, 'Luke Combs')]]),
            hasFrontCover: true,
        );
        $this->musicBrainz->releases[$this->release->id] = $this->release;

        $suffix = random_bytes(4) |> bin2hex(...);
        $library = new LibraryEntity('Long Bed Match', 'long-bed-match-' . $suffix, '/media/long-bed-match-' . $suffix, 'music', 'local');
        $this->album = new AlbumEntity(new PublicId(), $library, self::ALBUM_TITLE, 'album');
        $this->album->setYear(2023);
        $this->cover = new ImageEntity('/images/long-bed-' . $suffix . '.webp', 'webp', 'image/webp', new PublicId(), 32, 2, 2, 'album');
        $this->album->setCoverImage($this->cover);
        $this->postMalone = new ArtistEntity(new PublicId(), self::ALBUM_ARTIST);
        $this->duetArtist = new ArtistEntity(new PublicId(), 'Post Malone, Luke Combs');
        $entities = [$library, $this->album, $this->cover, $this->postMalone, $this->duetArtist, new ArtistAlbumEntity($this->postMalone, $this->album, 'primary', self::ALBUM_ARTIST)];
        foreach ($this->release->media[0]->tracks as $track) {
            $song = new SongEntity(new PublicId(), $this->album, $track->title, sprintf('%s/%d.flac', $library->getPath(), $track->position), 1_000, 'audio/flac');
            $song->setTrack($track->position);
            $song->setDisc(1);
            $song->setLength(($track->lengthMs ?? 0) / 1000 + 1.5);
            $artist = $track->position === 2 ? $this->duetArtist : $this->postMalone;
            $this->longBedSongs[] = $song;
            $entities = [...$entities, $song, new ArtistSongEntity($artist, $song, 'primary', $artist->getName())];
        }
        foreach ($entities as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    private function albumPath(string $route): string
    {
        return sprintf('/api/admin/metadata/match/album/%s/%s', $this->album->getPublicId()->toString(), $route);
    }

    /**
     * The apply body a client sends for the comparison's default ticks: every ticked row by its key
     * with the current value it showed, and each listed merge or removal as ticked or kept.
     *
     * @param array<string, mixed> $comparison the comparison route's data
     *
     * @return array<string, mixed>
     */
    private static function defaultTicks(array $comparison): array
    {
        $tracks = $comparison['tracks'];
        $fieldRows = [$comparison['fields'], ...array_column($tracks, 'fields')] |> (static fn (array $rows): array => array_merge(...$rows));
        $creditRows = [$comparison['credits'], ...array_column($tracks, 'credits')] |> array_filter(...);
        $ticked = static fn (array $rows): array => array_filter($rows, static fn (array $row): bool => $row['ticked'] === true) |> array_values(...);
        $actions = static fn (bool $ticked): array => array_filter($comparison['artistActions'], static fn (array $action): bool => $action['ticked'] === $ticked)
            |> (static fn (array $listed): array => array_column($listed, 'artistPublicId'));

        return [
            'candidate' => $comparison['candidateId'],
            'fields' => array_map(static fn (array $row): array => ['key' => $row['key'], 'current' => $row['current']], $ticked($fieldRows)),
            'credits' => array_map(
                static fn (array $row): array => [
                    'key' => $row['key'],
                    'current' => array_map(static fn (array $credit): array => array_intersect_key($credit, ['artistPublicId' => 0, 'role' => 0, 'creditedName' => 0]), $row['current']),
                ],
                $ticked($creditRows),
            ),
            'artistActions' => $actions(true),
            'keptArtists' => $actions(false),
        ];
    }

    /** @return list<array{string, string, string}> the name, role and credited name of each credit of the song, primary first */
    private function creditsOf(SongEntity $song): array
    {
        return $this->entityManager->getConnection()->fetchAllNumeric(
            "SELECT a.name, s.role, s.credited_name FROM artist_song s JOIN artists a ON a.id = s.artist_id WHERE s.song_id = ? ORDER BY s.role <> 'primary', s.credited_name",
            [$song->getId()->toString()],
        ) |> (static fn (array $rows): array => array_map(static fn (array $row): array => [(string) $row[0], (string) $row[1], (string) $row[2]], $rows));
    }

    private function artistCount(string $name): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM artists WHERE name = ?', [$name]);
    }

    private function releaseOfAlbum(): ?string
    {
        $release = $this->entityManager->getConnection()->fetchOne('SELECT release_mbid FROM albums WHERE id = ?', [$this->album->getId()->toString()]);

        return is_string($release) ? $release : null;
    }
}
