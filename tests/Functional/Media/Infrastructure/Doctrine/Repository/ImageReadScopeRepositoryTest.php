<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media\Infrastructure\Doctrine\Repository;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Media\Domain\Repository\ImageRepositoryInterface;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Domain\ValueObject\MediaReadScope;
use App\Tests\Functional\TestCase;
use LogicException;

final class ImageReadScopeRepositoryTest extends TestCase
{
    /** @var array<string, ImageEntity> */
    private array $images;
    private AlbumEntity $allowedAlbum;
    private AlbumEntity $deniedAlbum;
    private ArtistEntity $sharedArtist;
    private PlaylistEntity $ownedPlaylist;
    private PlaylistEntity $otherPlaylist;
    private MediaReadScope $scope;
    private MediaReadScope $adminScope;
    private MediaReadScope $noLibraries;
    private ImageRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $user = new UserEntity(new PublicId(), 'Member', 'image-member@baander.app', 'test-only', '');
        $other = new UserEntity(new PublicId(), 'Other', 'image-other@baander.app', 'test-only', '');
        $allowed = new LibraryEntity('Allowed', 'allowed', '/media/allowed', 'music', 'local');
        $denied = new LibraryEntity('Denied', 'denied', '/media/denied', 'music', 'local');
        $this->allowedAlbum = new AlbumEntity(new PublicId(), $allowed, 'Allowed Album', 'album');
        $this->deniedAlbum = new AlbumEntity(new PublicId(), $denied, 'Denied Album', 'album');
        $this->sharedArtist = new ArtistEntity(new PublicId(), 'Shared Artist');
        $deniedArtist = new ArtistEntity(new PublicId(), 'Denied Artist');
        $orphanArtist = new ArtistEntity(new PublicId(), 'Orphan Artist');
        $this->ownedPlaylist = new PlaylistEntity(new PublicId(), $user, 'Owned Playlist');
        $this->otherPlaylist = new PlaylistEntity(new PublicId(), $other, 'Other Playlist');
        $this->otherPlaylist->setPublic(true);
        $this->images = [];
        foreach (['album', 'album-reverse', 'artist', 'artist-reverse', 'playlist', 'other-playlist',
            'denied-album', 'denied-artist', 'orphan-artist', 'orphan', 'shared'] as $name) {
            $this->images[$name] = new ImageEntity(
                '/images/' . $name . '.webp', 'webp', 'image/webp', new PublicId(), 32, 2, 2, 'album',
            );
        }
        $this->images['album']->setAlbum($this->allowedAlbum);
        $this->allowedAlbum->setCoverImage($this->images['album-reverse']);
        $this->images['artist']->setArtist($this->sharedArtist);
        $this->sharedArtist->setCoverImage($this->images['artist-reverse']);
        $this->images['playlist']->setPlaylist($this->ownedPlaylist);
        $this->images['other-playlist']->setPlaylist($this->otherPlaylist);
        $this->images['denied-album']->setAlbum($this->deniedAlbum);
        $this->images['denied-artist']->setArtist($deniedArtist);
        $this->images['orphan-artist']->setArtist($orphanArtist);
        $this->images['shared']->setAlbum($this->deniedAlbum);
        $this->images['shared']->setArtist($this->sharedArtist);
        $this->images['shared']->setPlaylist($this->otherPlaylist);
        foreach ([
            $user, $other, $allowed, $denied, $this->allowedAlbum, $this->deniedAlbum,
            $this->sharedArtist, $deniedArtist, $orphanArtist, $this->ownedPlaylist, $this->otherPlaylist,
            ...array_values($this->images),
            new ArtistAlbumEntity($this->sharedArtist, $this->allowedAlbum, 'primary'),
            new ArtistAlbumEntity($this->sharedArtist, $this->allowedAlbum, 'featured'),
            new ArtistAlbumEntity($this->sharedArtist, $this->deniedAlbum, 'primary'),
            new ArtistAlbumEntity($deniedArtist, $this->deniedAlbum, 'primary'),
        ] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->scope = MediaReadScope::authenticated($user->getId(), LibraryReadScope::restricted([$allowed->getId()]));
        $this->adminScope = MediaReadScope::authenticated($user->getId(), LibraryReadScope::unrestricted());
        $this->noLibraries = MediaReadScope::authenticated($user->getId(), LibraryReadScope::none());
        $this->repository = static::getContainer()->get(ImageRepositoryInterface::class);
        $this->entityManager->clear();
    }

    public function testForwardAndReverseCoverAssociationsAuthorizeImageReads(): void
    {
        foreach (['album', 'album-reverse', 'artist', 'artist-reverse', 'playlist', 'shared'] as $name) {
            $view = $this->repository->findVisibleByPublicId($this->images[$name]->getPublicId(), $this->scope);
            $this->assertNotNull($view, $name);
            $this->assertSame($this->images[$name]->getId()->toString(), $view->getId()->toString());
            $this->assertSame('/images/' . $name . '.webp', $view->getPath());
        }
        $this->assertNull($this->images['album-reverse']->getAlbum());
        $this->assertNull($this->images['artist-reverse']->getArtist());
    }

    public function testUnrelatedPublicPlaylistAndOrphanImagesRemainDenied(): void
    {
        foreach (['other-playlist', 'denied-album', 'denied-artist', 'orphan-artist', 'orphan'] as $name) {
            $this->assertNull($this->repository->findVisibleByPublicId($this->images[$name]->getPublicId(), $this->scope));
            $this->assertNotNull($this->repository->findVisibleByPublicId($this->images[$name]->getPublicId(), $this->adminScope));
        }
        foreach ($this->images as $image) {
            $this->assertNull($this->repository->findVisibleByPublicId($image->getPublicId(), MediaReadScope::none()));
        }
        $this->assertNull($this->repository->findVisibleByPublicId($this->images['album']->getPublicId(), $this->noLibraries));
        $this->assertNotNull($this->repository->findVisibleByPublicId($this->images['playlist']->getPublicId(), $this->noLibraries));
    }

    public function testProjectionHidesUnrelatedOwnerIdsWithoutChangingStoredAssociations(): void
    {
        $image = $this->images['shared'];
        $view = $this->repository->findVisibleByPublicId($image->getPublicId(), $this->scope);
        $this->assertNotNull($view);
        $this->assertNull($view->getAlbumId());
        $this->assertNull($view->getPlaylistId());
        $this->assertSame($this->sharedArtist->getId()->toString(), $view->getArtistId()?->toString());
        $this->assertTrue($this->repository->saveVisibleBlurhash($image->getId(), 'authorized-hash', $this->scope));
        $stored = $this->repository->findVisibleByPublicId($image->getPublicId(), $this->adminScope);
        $this->assertNotNull($stored);
        $this->assertSame($this->deniedAlbum->getId()->toString(), $stored->getAlbumId()?->toString());
        $this->assertSame($this->otherPlaylist->getId()->toString(), $stored->getPlaylistId()?->toString());
        $this->assertSame('authorized-hash', $stored->getBlurhash());
    }

    public function testAtomicBlurhashWriteRespectsCurrentExplicitScope(): void
    {
        $image = $this->images['album'];
        $view = $this->repository->findVisibleByPublicId($image->getPublicId(), $this->scope);
        $this->assertNotNull($view);
        $this->assertNull($view->getBlurhash());
        $this->assertTrue($this->repository->saveVisibleBlurhash($image->getId(), 'first-hash', $this->scope));
        $this->assertFalse($this->repository->saveVisibleBlurhash($image->getId(), 'revoked-hash', $this->noLibraries));
        $this->assertFalse($this->repository->saveVisibleBlurhash($image->getId(), 'anonymous-hash', MediaReadScope::none()));
        $this->assertFalse($this->repository->saveVisibleBlurhash($this->images['other-playlist']->getId(), 'other-hash', $this->scope));
        $this->assertSame('first-hash', $this->repository->findVisibleByPublicId($image->getPublicId(), $this->adminScope)?->getBlurhash());
        $this->assertNull($this->repository->findVisibleByPublicId($this->images['other-playlist']->getPublicId(), $this->adminScope)?->getBlurhash());
    }

    public function testManagedImageBoundaryPreservesDirtyOwnerAndMetadataChanges(): void
    {
        $imageId = $this->images['album']->getId();
        $managed = $this->entityManager->find(ImageEntity::class, $imageId);
        $this->assertNotNull($managed);
        $managed->setPath('/images/pending.webp');
        $managed->setAlbum($this->entityManager->getReference(AlbumEntity::class, $this->deniedAlbum->getId()));
        try {
            $this->repository->saveVisibleBlurhash($imageId, 'rejected-hash', $this->scope);
            $this->fail('A managed image must reject the atomic bulk write.');
        } catch (LogicException) {
            $this->assertSame('/images/pending.webp', $managed->getPath());
            $this->assertSame($this->deniedAlbum->getId()->toString(), $managed->getAlbum()?->getId()->toString());
        }
        $row = $this->entityManager->getConnection()->executeQuery(
            'SELECT path, blurhash, album_id FROM images WHERE id = :id',
            ['id' => $imageId->toString()],
        )->fetchAssociative();
        $this->assertIsArray($row);
        $this->assertSame('/images/album.webp', $row['path']);
        $this->assertNull($row['blurhash']);
        $this->assertSame($this->allowedAlbum->getId()->toString(), $row['album_id']);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $stored = $this->repository->findVisibleByPublicId($this->images['album']->getPublicId(), $this->adminScope);
        $this->assertNotNull($stored);
        $this->assertSame('/images/pending.webp', $stored->getPath());
        $this->assertSame($this->deniedAlbum->getId()->toString(), $stored->getAlbumId()?->toString());
        $this->assertNull($stored->getBlurhash());
    }
}
