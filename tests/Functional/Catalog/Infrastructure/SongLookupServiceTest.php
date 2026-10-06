<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Infrastructure;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Functional\TestCase;

/** The published song lookup against the migrated PostgreSQL schema. */
final class SongLookupServiceTest extends TestCase
{
    private SongLookupInterface $lookup;
    private SongEntity $allowedSong;
    private SongEntity $deniedSong;
    private LibraryReadScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $allowedLibrary = new LibraryEntity('Lookup allowed', 'lookup-allowed', '/lookup/allowed', 'music', 'local');
        $deniedLibrary = new LibraryEntity('Lookup denied', 'lookup-denied', '/lookup/denied', 'music', 'local');
        $allowedAlbum = new AlbumEntity(new PublicId(), $allowedLibrary, 'Allowed', 'album');
        $deniedAlbum = new AlbumEntity(new PublicId(), $deniedLibrary, 'Denied', 'album');
        $this->allowedSong = new SongEntity(new PublicId(), $allowedAlbum, 'Allowed', '/lookup/allowed/song.flac', 1, 'audio/flac');
        $this->deniedSong = new SongEntity(new PublicId(), $deniedAlbum, 'Denied', '/lookup/denied/song.flac', 1, 'audio/flac');
        foreach ([$allowedLibrary, $deniedLibrary, $allowedAlbum, $deniedAlbum, $this->allowedSong, $this->deniedSong] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->scope = LibraryReadScope::restricted([$allowedLibrary->getId()]);
        $this->lookup = static::getContainer()->get(SongLookupInterface::class);
    }

    public function testVisibleSongIdByPublicIdFollowsTheScope(): void
    {
        self::assertEquals($this->allowedSong->getId(), $this->lookup->findVisibleSongId($this->allowedSong->getPublicId(), $this->scope));
        self::assertNull($this->lookup->findVisibleSongId($this->deniedSong->getPublicId(), $this->scope));
        self::assertNull($this->lookup->findVisibleSongId($this->allowedSong->getPublicId(), LibraryReadScope::none()));
        self::assertEquals($this->deniedSong->getId(), $this->lookup->findVisibleSongId($this->deniedSong->getPublicId(), LibraryReadScope::unrestricted()));
        self::assertNull($this->lookup->findVisibleSongId(new PublicId(), LibraryReadScope::unrestricted()));
    }

    public function testVisibleSubsetFiltersByScopeAndIgnoresUnknownAndRepeatedIds(): void
    {
        $ids = [$this->allowedSong->getId(), $this->deniedSong->getId(), $this->allowedSong->getId(), new Uuid()];

        self::assertSame([], $this->lookup->visibleSongIds([], $this->scope));
        self::assertSame([], $this->lookup->visibleSongIds($ids, LibraryReadScope::none()));
        self::assertSame([$this->allowedSong->getId()->toString()], $this->strings($this->lookup->visibleSongIds($ids, $this->scope)));
        $all = $this->strings($this->lookup->visibleSongIds($ids, LibraryReadScope::unrestricted()));
        sort($all);
        $expected = [$this->allowedSong->getId()->toString(), $this->deniedSong->getId()->toString()];
        sort($expected);
        self::assertSame($expected, $all);
    }

    public function testVisibleSubsetBindsTheSetAsOneParameterBeyondTheBindLimit(): void
    {
        // PostgreSQL rejects more than 65,535 bind parameters; an expanded IN list would fail here.
        $ids = array_map(static fn (): Uuid => new Uuid(), range(1, 70_000));
        $ids[] = $this->deniedSong->getId();
        $ids[] = $this->allowedSong->getId();

        self::assertSame([$this->allowedSong->getId()->toString()], $this->strings($this->lookup->visibleSongIds($ids, $this->scope)));
    }

    public function testSongIdPagesWalkEverySongOnceInIdOrder(): void
    {
        $walked = [];
        $after = null;
        $pages = 0;
        do {
            $page = $this->lookup->songIdsAfter($after, 1);
            self::assertLessThanOrEqual(1, count($page));
            foreach ($page as $id) {
                $walked[] = $id->toString();
                $after = $id;
            }
            self::assertLessThan(10_000, ++$pages, 'The song-ID walk did not terminate.');
        } while ($page !== []);

        $sorted = $walked;
        sort($sorted);
        self::assertSame($sorted, $walked);
        self::assertSame(array_values(array_unique($walked)), $walked);
        self::assertContains($this->allowedSong->getId()->toString(), $walked);
        self::assertContains($this->deniedSong->getId()->toString(), $walked);
        self::assertSame(
            (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM songs'),
            count($walked),
        );
    }

    /**
     * @param list<Uuid> $ids
     * @return list<string>
     */
    private function strings(array $ids): array
    {
        return array_map(static fn (Uuid $id): string => $id->toString(), $ids);
    }
}
