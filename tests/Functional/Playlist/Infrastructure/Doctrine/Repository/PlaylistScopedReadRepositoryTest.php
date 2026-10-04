<?php

declare(strict_types=1);

namespace App\Tests\Functional\Playlist\Infrastructure\Doctrine\Repository;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistEntity;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistSongEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Functional\TestCase;

final class PlaylistScopedReadRepositoryTest extends TestCase
{
    private UserEntity $owner;
    private SongEntity $allowedSong;
    private SongEntity $deniedSong;
    private AlbumEntity $deniedAlbum;
    private PlaylistEntity $mixed;
    private LibraryReadScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = new UserEntity(new PublicId(), 'Owner', 'owner@baander.app', 'hash', '');
        $other = new UserEntity(new PublicId(), 'Other', 'other@baander.app', 'hash', '');
        $allowedLibrary = new LibraryEntity('Allowed', 'allowed', '/allowed', 'music', 'local');
        $deniedLibrary = new LibraryEntity('Denied', 'denied', '/denied', 'music', 'local');
        $allowedAlbum = new AlbumEntity(new PublicId(), $allowedLibrary, 'Allowed', 'album');
        $this->deniedAlbum = new AlbumEntity(new PublicId(), $deniedLibrary, 'Denied', 'album');
        $this->allowedSong = new SongEntity(new PublicId(), $allowedAlbum, 'Allowed', '/allowed/song.flac', 1, 'audio/flac');
        $this->deniedSong = new SongEntity(new PublicId(), $this->deniedAlbum, 'Denied', '/denied/song.flac', 1, 'audio/flac');
        $this->mixed = new PlaylistEntity(new PublicId(), $this->owner, 'A Mixed');
        $empty = new PlaylistEntity(new PublicId(), $this->owner, 'B Empty');
        $denied = new PlaylistEntity(new PublicId(), $this->owner, 'C Denied');
        $foreign = new PlaylistEntity(new PublicId(), $other, 'Foreign Public');
        $foreign->setPublic(true);
        foreach ([
            $this->owner, $other, $allowedLibrary, $deniedLibrary, $allowedAlbum,
            $this->deniedAlbum, $this->allowedSong, $this->deniedSong,
            $this->mixed, $empty, $denied, $foreign,
            new PlaylistSongEntity($this->mixed, $this->allowedSong, 3),
            new PlaylistSongEntity($this->mixed, $this->deniedSong, 7),
            new PlaylistSongEntity($denied, $this->deniedSong),
            new PlaylistSongEntity($foreign, $this->allowedSong),
        ] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->scope = LibraryReadScope::restricted([$allowedLibrary->getId()]);
    }

    public function testCountsPreserveEmptyAndFilteredPlaylistsWithoutLoadingOrStrippingChildren(): void
    {
        $repository = static::getContainer()->get(PlaylistRepositoryInterface::class);
        $views = $repository->findReadByUser($this->owner->getId(), $this->scope);
        $this->assertSame(['A Mixed', 'B Empty', 'C Denied'], array_map(static fn ($view) => $view->getName(), $views));
        $this->assertSame([1, 0, 0], array_map(static fn ($view) => $view->getSongCount(), $views));
        $this->assertSame([2, 0, 1], array_map(static fn ($view) => $view->getSongCount(), $repository->findReadByUser($this->owner->getId(), LibraryReadScope::unrestricted())));
        $this->assertSame([0, 0, 0], array_map(static fn ($view) => $view->getSongCount(), $repository->findReadByUser($this->owner->getId(), LibraryReadScope::none())));
        $this->assertSame([], $repository->findReadByUser(new Uuid(), LibraryReadScope::unrestricted()));
        $this->mixed->setName('Pending name');
        $this->assertSame('A Mixed', $repository->findReadByUser($this->owner->getId(), $this->scope)[0]->getName());
        $this->assertSame('Pending name', $this->mixed->getName());
        $id = $this->mixed->getId();
        $this->entityManager->flush();
        $this->entityManager->clear();
        $stored = $repository->findWithSongs($id);
        $this->assertNotNull($stored);
        $this->assertSame('Pending name', $stored->getName());
        $this->assertSame([3, 7], array_map(static fn ($song) => $song->getPosition(), $stored->getSongs()));
    }

    public function testBulkSongReadsAreKeyedScopedSnapshotsIndependentOfManagedDirtyEntities(): void
    {
        $repository = static::getContainer()->get(SongRepositoryInterface::class);
        $ids = [$this->allowedSong->getId(), $this->deniedSong->getId(), $this->allowedSong->getId(), new Uuid()];
        $this->assertSame([], $repository->findVisibleByUuids([], $this->scope));
        $this->assertSame([], $repository->findVisibleByUuids($ids, LibraryReadScope::none()));
        $this->assertCount(2, $repository->findVisibleByUuids($ids, LibraryReadScope::unrestricted()));
        $this->allowedSong->setTitle('Pending title');
        $this->allowedSong->setAlbum($this->deniedAlbum);
        $songs = $repository->findVisibleByUuids($ids, $this->scope);
        $this->assertSame([$this->allowedSong->getId()->toString()], array_keys($songs));
        $song = $songs[$this->allowedSong->getId()->toString()];
        $this->assertSame('Allowed', $song->getTitle());
        $this->assertNotEquals($this->deniedAlbum->getId(), $song->getAlbumId());
        $this->assertSame('Pending title', $this->allowedSong->getTitle());
        $this->assertSame($this->deniedAlbum, $this->allowedSong->getAlbum());
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->assertSame([], $repository->findVisibleByUuids($ids, $this->scope));
    }
}
