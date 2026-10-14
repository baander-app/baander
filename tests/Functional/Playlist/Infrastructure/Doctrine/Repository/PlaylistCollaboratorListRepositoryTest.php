<?php

declare(strict_types=1);

namespace App\Tests\Functional\Playlist\Infrastructure\Doctrine\Repository;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistCollaboratorEntity;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistEntity;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistSongEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Functional\TestCase;

/** The list names the playlists the actor owns or collaborates on, with the owner's public id and the actor's visible count. */
final class PlaylistCollaboratorListRepositoryTest extends TestCase
{
    private UserEntity $owner;
    private UserEntity $collaborator;
    private PlaylistEntity $shared;
    private PlaylistEntity $own;
    private LibraryEntity $collaboratorLibrary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = new UserEntity(new PublicId(), 'Owner', 'owner@baander.app', 'hash', '');
        $this->collaborator = new UserEntity(new PublicId(), 'Collaborator', 'collaborator@baander.app', 'hash', '');
        $this->collaboratorLibrary = new LibraryEntity('Visible', 'visible', '/visible', 'music', 'local');
        $ownerOnlyLibrary = new LibraryEntity('Hidden', 'hidden', '/hidden', 'music', 'local');
        $visibleAlbum = new AlbumEntity(new PublicId(), $this->collaboratorLibrary, 'Visible', 'album');
        $hiddenAlbum = new AlbumEntity(new PublicId(), $ownerOnlyLibrary, 'Hidden', 'album');
        $visibleSong = new SongEntity(new PublicId(), $visibleAlbum, 'Visible', '/visible/song.flac', 1, 'audio/flac');
        $hiddenSong = new SongEntity(new PublicId(), $hiddenAlbum, 'Hidden', '/hidden/song.flac', 1, 'audio/flac');
        $this->shared = new PlaylistEntity(new PublicId(), $this->owner, 'A Shared');
        $private = new PlaylistEntity(new PublicId(), $this->owner, 'B Private');
        $this->own = new PlaylistEntity(new PublicId(), $this->collaborator, 'C Own');
        foreach ([
            $this->owner, $this->collaborator, $this->collaboratorLibrary, $ownerOnlyLibrary, $visibleAlbum, $hiddenAlbum,
            $visibleSong, $hiddenSong, $this->shared, $private, $this->own,
            new PlaylistSongEntity($this->shared, $visibleSong->getId(), 0),
            new PlaylistSongEntity($this->shared, $hiddenSong->getId(), 1),
            new PlaylistSongEntity($private, $visibleSong->getId(), 0),
            new PlaylistCollaboratorEntity($this->shared, $this->collaborator),
        ] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    public function testACollaboratorListsTheSharedPlaylistWithTheOwnersPublicIdAndTheirOwnVisibleCount(): void
    {
        $repository = static::getContainer()->get(PlaylistRepositoryInterface::class);
        $scope = LibraryReadScope::restricted([$this->collaboratorLibrary->getId()]);

        $views = $repository->findReadByUser($this->collaborator->getId(), $scope);

        $this->assertSame(['A Shared', 'C Own'], array_map(static fn ($view) => $view->getName(), $views));
        $this->assertSame($this->owner->getPublicId()->toString(), $views[0]->getOwnerPublicId()->toString());
        $this->assertSame([$this->collaborator->getPublicId()->toString()], array_map(static fn (PublicId $id) => $id->toString(), $views[0]->getCollaboratorPublicIds()));
        $this->assertSame([1, 0], array_map(static fn ($view) => $view->getSongCount(), $views));
        $this->assertSame([2, 0], array_map(static fn ($view) => $view->getSongCount(), $repository->findReadByUser($this->collaborator->getId(), LibraryReadScope::unrestricted())));
    }

    public function testTheOwnerListsEveryOwnedPlaylistAndNotTheCollaboratorsOwn(): void
    {
        $repository = static::getContainer()->get(PlaylistRepositoryInterface::class);

        $views = $repository->findReadByUser($this->owner->getId(), LibraryReadScope::unrestricted());

        $this->assertSame(['A Shared', 'B Private'], array_map(static fn ($view) => $view->getName(), $views));
        $this->assertSame([[$this->collaborator->getPublicId()->toString()], []], array_map(
            static fn ($view) => array_map(static fn (PublicId $id) => $id->toString(), $view->getCollaboratorPublicIds()),
            $views,
        ));
    }

    public function testOnePlaylistReadsWithTheViewersVisibleCount(): void
    {
        $repository = static::getContainer()->get(PlaylistRepositoryInterface::class);

        $view = $repository->findRead($this->shared->getId(), LibraryReadScope::restricted([$this->collaboratorLibrary->getId()]));

        $this->assertNotNull($view);
        $this->assertSame(1, $view->getSongCount());
        $this->assertSame($this->owner->getPublicId()->toString(), $view->getOwnerPublicId()->toString());
        $this->assertNull($repository->findRead(new \App\Shared\Domain\Model\Uuid(), LibraryReadScope::unrestricted()));
    }
}
