<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

final class SongLibraryLookupRepositoryTest extends TestCase
{
    public function testReturnsAlbumLibraryUuidForPersistedSong(): void
    {
        $library = new LibraryEntity('Lookup Library', 'lookup-library', '/media/lookup', 'music', 'local');
        $album = new AlbumEntity(new PublicId(), $library, 'Lookup Album', 'album');
        $song = new SongEntity(new PublicId(), $album, 'Lookup Song', '/media/lookup/song.flac', 1, 'audio/flac');
        foreach ([$library, $album, $song] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $publicId = $song->getPublicId();
        $expectedLibraryId = $library->getId()->toString();
        $this->entityManager->clear();

        $repository = static::getContainer()->get(SongRepositoryInterface::class);
        $libraryId = $repository->getLibraryIdByPublicId($publicId);

        $this->assertInstanceOf(Uuid::class, $libraryId);
        $this->assertSame($expectedLibraryId, $libraryId->toString());
    }

    public function testReturnsNullForMissingSong(): void
    {
        $repository = static::getContainer()->get(SongRepositoryInterface::class);

        $this->assertNull($repository->getLibraryIdByPublicId(new PublicId()));
    }
}
