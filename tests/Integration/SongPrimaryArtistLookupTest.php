<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Catalog\Infrastructure\Doctrine\Repository\ArtistRepository;
use App\Catalog\Infrastructure\Doctrine\Repository\SongRepository;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/** A song may have several primary artists; the artist-name lookups pick one deterministically. */
final class SongPrimaryArtistLookupTest extends TestCase
{
    use OwnershipPersistenceHarness;

    public function testSongWithTwoPrimaryArtistsResolvesToTheAlphabeticallyFirst(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $library = new LibraryEntity('Primary artists', 'primary-artists-' . $suffix, '/primary-artists', 'music', 'local');
        $album = new AlbumEntity(new PublicId(), $library, 'Duets', 'album');
        $song = new SongEntity(new PublicId(), $album, 'Duet', '/primary-artists/duet.flac', 1, 'audio/flac');
        $solo = new SongEntity(new PublicId(), $album, 'Solo', '/primary-artists/solo.flac', 1, 'audio/flac');
        $tagged = new ArtistEntity(new PublicId(), 'Zeta ' . $suffix);
        $added = new ArtistEntity(new PublicId(), 'Alpha ' . $suffix);
        foreach ([$library, $album, $song, $solo, $tagged, $added] as $entity) {
            $this->manager->persist($entity);
        }
        $this->manager->flush();

        $songs = new SongRepository($this->manager, new CursorPaginator(), new CursorCodec(new JsonEncoder()));
        // The scanner links the tagged artist; an admin then adds a second primary artist.
        $songs->linkArtistToSong($song->getId(), $tagged->getName(), ArtistRole::Primary->value);
        $songs->linkArtistToSong($solo->getId(), $tagged->getName(), ArtistRole::Primary->value);
        $this->manager->flush();
        (new ArtistRepository($this->manager))->addSongToArtist($added->getId(), $song->getId(), ArtistRole::Primary->value);
        $this->manager->clear();

        self::assertSame(2, (int) $this->manager->getConnection()->fetchOne(
            'SELECT count(*) FROM artist_song WHERE song_id = :song AND role = :role',
            ['song' => $song->getId()->toString(), 'role' => ArtistRole::Primary->value],
        ));
        self::assertSame($added->getName(), $songs->getArtistNameForSong($song->getId()));
        self::assertSame($tagged->getName(), $songs->getArtistNameForSong($solo->getId()));
        self::assertSame([
            $song->getId()->toString() => $added->getName(),
            $solo->getId()->toString() => $tagged->getName(),
        ], $songs->getArtistNamesForSongs([$song->getId(), $solo->getId()]));
    }
}
