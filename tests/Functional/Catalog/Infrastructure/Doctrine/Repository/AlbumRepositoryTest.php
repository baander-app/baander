<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumType;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

final class AlbumRepositoryTest extends TestCase
{
    private AlbumRepositoryInterface $albumRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->albumRepository = $container->get(AlbumRepositoryInterface::class);
    }

    public function testFindByMbidAndLibraryReturnsMatchingAlbum(): void
    {
        $libraryId = $this->createLibraryFixture();
        $albumId = Uuid::v7();
        $publicId = new PublicId();
        $mbid = MusicbrainzId::fromString('12345678-1234-1234-1234-123456789abc');
        $now = new \DateTimeImmutable();

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'INSERT INTO albums (id, public_id, library_id, title, type, mbid, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $albumId->toString(),
                $publicId->toString(),
                $libraryId->toString(),
                'Test Album',
                'studio',
                $mbid->toString(),
                '{}',
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );

        $result = $this->albumRepository->findByMbidAndLibrary($mbid, $libraryId);

        $this->assertNotNull($result);
        $this->assertSame($albumId->toString(), $result->getId()->toString());
        $this->assertSame($mbid->toString(), $result->getMbid());
    }

    public function testFindByMbidAndLibraryReturnsNullWhenMbidIsNull(): void
    {
        $libraryId = $this->createLibraryFixture();

        $result = $this->albumRepository->findByMbidAndLibrary(null, $libraryId);

        $this->assertNull($result);
    }

    public function testFindByMbidAndLibraryReturnsNullForEmptyMbid(): void
    {
        $libraryId = $this->createLibraryFixture();
        $emptyMbid = MusicbrainzId::fromString('');

        $result = $this->albumRepository->findByMbidAndLibrary($emptyMbid, $libraryId);

        $this->assertNull($result);
    }

    public function testFindByMbidAndLibraryReturnsNullForDifferentLibrary(): void
    {
        $libraryId1 = $this->createLibraryFixture();
        $libraryId2 = $this->createLibraryFixture();
        $albumId = Uuid::v7();
        $publicId = new PublicId();
        $mbid = MusicbrainzId::fromString('87654321-4321-4321-4321-cba987654321');
        $now = new \DateTimeImmutable();

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'INSERT INTO albums (id, public_id, library_id, title, type, mbid, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $albumId->toString(),
                $publicId->toString(),
                $libraryId1->toString(),
                'Test Album',
                'studio',
                $mbid->toString(),
                '{}',
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );

        $result = $this->albumRepository->findByMbidAndLibrary($mbid, $libraryId2);

        $this->assertNull($result);
    }

    public function testFindByMbidAndLibraryHandlesMultipleAlbumsWithSameMbid(): void
    {
        $libraryId1 = $this->createLibraryFixture();
        $libraryId2 = $this->createLibraryFixture();
        $albumId1 = Uuid::v7();
        $albumId2 = Uuid::v7();
        $publicId1 = new PublicId();
        $publicId2 = new PublicId();
        $mbid = MusicbrainzId::fromString('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
        $now = new \DateTimeImmutable();

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'INSERT INTO albums (id, public_id, library_id, title, type, mbid, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $albumId1->toString(),
                $publicId1->toString(),
                $libraryId1->toString(),
                'Test Album 1',
                'studio',
                $mbid->toString(),
                '{}',
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );
        $conn->executeStatement(
            'INSERT INTO albums (id, public_id, library_id, title, type, mbid, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $albumId2->toString(),
                $publicId2->toString(),
                $libraryId2->toString(),
                'Test Album 2',
                'studio',
                $mbid->toString(),
                '{}',
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );

        $result = $this->albumRepository->findByMbidAndLibrary($mbid, $libraryId1);

        $this->assertNotNull($result);
        $this->assertSame($albumId1->toString(), $result->getId()->toString());
    }

    private function createLibraryFixture(): Uuid
    {
        $libraryId = Uuid::v7();
        $now = new \DateTimeImmutable();

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'INSERT INTO libraries (id, slug, name, path, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $libraryId->toString(),
                'test-lib-' . bin2hex(random_bytes(4)),
                'Test Library',
                '/tmp/test-' . bin2hex(random_bytes(4)),
                'music',
                0,
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );

        return $libraryId;
    }
}
