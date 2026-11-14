<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Catalog\Application\Port\SongLyricSignature;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Catalog\Infrastructure\Doctrine\Repository\SongRepository;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * A song shows the name its primary credit was given, not the credited artist's
 * own name, and lyrics lookups send that name. A song with several primary
 * credits shows one of them deterministically.
 */
final class SongPrimaryArtistLookupTest extends TestCase
{
    use OwnershipPersistenceHarness {
        setUp as private setUpHarness;
    }

    private LibraryEntity $library;
    private AlbumEntity $album;

    protected function setUp(): void
    {
        $this->setUpHarness();
        $this->library = new LibraryEntity('Credited names', 'credited-names-' . bin2hex(random_bytes(6)), '/credited-names', 'music', 'local');
        $this->album = new AlbumEntity(new PublicId(), $this->library, 'Credits', 'album');
        $this->manager->persist($this->library);
        $this->manager->persist($this->album);
    }

    public function testASongShowsTheNameItWasCreditedUnderAndItsLyricLookupSendsIt(): void
    {
        $artist = $this->artist('Headhunterz');
        $first = $this->song('Scrap Attack');
        $second = $this->song('Psychedelic');
        $this->credit($artist, $first, 'Headhunterz');
        $this->credit($artist, $second, 'HeadHunterz');
        $this->manager->flush();
        $this->manager->clear();

        $songs = $this->songs();
        self::assertSame('HeadHunterz', $songs->getArtistNameForSong($second->getId()));
        $expected = [
            $first->getId()->toString() => 'Headhunterz',
            $second->getId()->toString() => 'HeadHunterz',
        ];
        self::assertSame($expected, $songs->getArtistNamesForSongs([$first->getId(), $second->getId()]));
        self::assertSame($expected, $songs->getVisibleArtistNamesForSongs(
            [$first->getId(), $second->getId()],
            LibraryReadScope::restricted([$this->library->getId()]),
        ));
        self::assertEquals(
            new SongLyricSignature('Psychedelic', 'HeadHunterz', 'Credits', null),
            $this->lookup()->findLyricSignature($second->getId()),
        );
    }

    public function testRenamingTheArtistLeavesTheShownNameUnchanged(): void
    {
        $artist = $this->artist('Headhunterz');
        $song = $this->song('Psychedelic');
        $this->credit($artist, $song, 'HeadHunterz');
        $this->manager->flush();
        $this->manager->getConnection()->executeStatement(
            'UPDATE artists SET name = :name WHERE id = :id',
            ['name' => 'Willem Rebergen', 'id' => $artist->getId()->toString()],
        );
        $this->manager->clear();

        self::assertSame('HeadHunterz', $this->songs()->getArtistNameForSong($song->getId()));
        self::assertSame('HeadHunterz', $this->lookup()->findLyricSignature($song->getId())?->artistName);
    }

    public function testASongWithTwoPrimaryCreditsShowsTheCreditedNameThatSortsFirst(): void
    {
        // By the artists' own names, the "Yankee" credit would come first.
        $alpha = $this->artist('Alpha');
        $zeta = $this->artist('Zeta');
        $duet = $this->song('Duet');
        $solo = $this->song('Solo');
        $this->credit($alpha, $duet, 'Yankee');
        $this->credit($zeta, $duet, 'Bravo');
        $this->credit($alpha, $solo, 'Yankee');
        // A featured credit is never the shown name, even when its name sorts first.
        $this->credit($alpha, $duet, 'Able', ArtistRole::Featured);
        $this->manager->flush();
        $this->manager->clear();

        self::assertSame(2, (int) $this->manager->getConnection()->fetchOne(
            'SELECT count(*) FROM artist_song WHERE song_id = :song AND role = :role',
            ['song' => $duet->getId()->toString(), 'role' => ArtistRole::Primary->value],
        ));
        $songs = $this->songs();
        self::assertSame('Bravo', $songs->getArtistNameForSong($duet->getId()));
        self::assertSame('Yankee', $songs->getArtistNameForSong($solo->getId()));
        self::assertSame([
            $duet->getId()->toString() => 'Bravo',
            $solo->getId()->toString() => 'Yankee',
        ], $songs->getArtistNamesForSongs([$duet->getId(), $solo->getId()]));
        self::assertSame('Bravo', $this->lookup()->findLyricSignature($duet->getId())?->artistName);
    }

    private function artist(string $name): ArtistEntity
    {
        $artist = new ArtistEntity(new PublicId(), $name);
        $this->manager->persist($artist);

        return $artist;
    }

    private function song(string $title): SongEntity
    {
        $song = new SongEntity(new PublicId(), $this->album, $title, '/credited-names/' . $title . '.flac', 1, 'audio/flac');
        $this->manager->persist($song);

        return $song;
    }

    private function credit(ArtistEntity $artist, SongEntity $song, string $creditedName, ArtistRole $role = ArtistRole::Primary): void
    {
        $this->manager->persist(new ArtistSongEntity($artist, $song, $role->value, $creditedName));
    }

    private function songs(): SongRepository
    {
        return new SongRepository($this->manager, new CursorPaginator(), new CursorCodec(new JsonEncoder()));
    }

    private function lookup(): SongLookupInterface
    {
        $lookup = $this->kernel->getContainer()->get('test.service_container')->get(SongLookupInterface::class);
        self::assertInstanceOf(SongLookupInterface::class, $lookup);

        return $lookup;
    }
}
