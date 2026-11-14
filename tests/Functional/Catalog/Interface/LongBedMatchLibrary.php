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
use App\Tests\Unit\Catalog\Application\QueryHandler\MusicBrainzMatch\MatchQueryFixtures;

/**
 * "F-1 Trillion: Long Bed" in PostgreSQL for the MusicBrainz match routes and commands, with
 * MusicBrainz answered by a fake in the container that knows the release the album fits.
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

    private function storeLongBed(): void
    {
        $this->setUpLibrary();
        static::getContainer()->set(MusicBrainzLookupInterface::class, $this->musicBrainz);
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
}
