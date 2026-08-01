<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Repository\ArtistRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

final class ArtistRepositoryTest extends TestCase
{
    private ArtistRepositoryInterface $artistRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->artistRepository = $container->get(ArtistRepositoryInterface::class);
    }

    public function testFindByUuidsReturnsArtistsKeyedByUuidString(): void
    {
        $artistId1 = Uuid::v7();
        $artistId2 = Uuid::v7();
        $now = new \DateTimeImmutable();

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'INSERT INTO artists (id, public_id, name, country, gender, type, life_span_begin, life_span_end, disambiguation, sort_name, biography, mbid, discogs_id, spotify_id, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $artistId1->toString(),
                (new \App\Shared\Domain\Model\PublicId())->toString(),
                'Artist One',
                'US',
                null,
                null,
                null,
                null,
                null,
                'Artist One',
                null,
                null,
                null,
                null,
                '{}',
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );
        $conn->executeStatement(
            'INSERT INTO artists (id, public_id, name, country, gender, type, life_span_begin, life_span_end, disambiguation, sort_name, biography, mbid, discogs_id, spotify_id, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $artistId2->toString(),
                (new \App\Shared\Domain\Model\PublicId())->toString(),
                'Artist Two',
                'UK',
                null,
                null,
                null,
                null,
                null,
                'Artist Two',
                null,
                null,
                null,
                null,
                '{}',
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );

        $result = $this->artistRepository->findByUuids([$artistId1, $artistId2]);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey($artistId1->toString(), $result);
        $this->assertArrayHasKey($artistId2->toString(), $result);
        $this->assertSame('Artist One', $result[$artistId1->toString()]->getName());
        $this->assertSame('Artist Two', $result[$artistId2->toString()]->getName());
    }

    public function testFindByUuidsReturnsEmptyArrayForEmptyInput(): void
    {
        $result = $this->artistRepository->findByUuids([]);

        $this->assertSame([], $result);
    }

    public function testFindByUuidsIgnoresNonExistentUuids(): void
    {
        $artistId = Uuid::v7();
        $nonExistentId = Uuid::v7();
        $now = new \DateTimeImmutable();

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'INSERT INTO artists (id, public_id, name, country, gender, type, life_span_begin, life_span_end, disambiguation, sort_name, biography, mbid, discogs_id, spotify_id, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $artistId->toString(),
                (new \App\Shared\Domain\Model\PublicId())->toString(),
                'Existing Artist',
                'US',
                null,
                null,
                null,
                null,
                null,
                'Existing Artist',
                null,
                null,
                null,
                null,
                '{}',
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );

        $result = $this->artistRepository->findByUuids([$artistId, $nonExistentId]);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey($artistId->toString(), $result);
        $this->assertArrayNotHasKey($nonExistentId->toString(), $result);
    }

    public function testFindByUuidsWithSingleUuid(): void
    {
        $artistId = Uuid::v7();
        $now = new \DateTimeImmutable();

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'INSERT INTO artists (id, public_id, name, country, gender, type, life_span_begin, life_span_end, disambiguation, sort_name, biography, mbid, discogs_id, spotify_id, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $artistId->toString(),
                (new \App\Shared\Domain\Model\PublicId())->toString(),
                'Single Artist',
                'US',
                null,
                null,
                null,
                null,
                null,
                'Single Artist',
                null,
                null,
                null,
                null,
                '{}',
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );

        $result = $this->artistRepository->findByUuids([$artistId]);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey($artistId->toString(), $result);
        $this->assertSame('Single Artist', $result[$artistId->toString()]->getName());
    }

    public function testFindByUuidsHandlesDuplicateUuids(): void
    {
        $artistId = Uuid::v7();
        $now = new \DateTimeImmutable();

        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'INSERT INTO artists (id, public_id, name, country, gender, type, life_span_begin, life_span_end, disambiguation, sort_name, biography, mbid, discogs_id, spotify_id, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $artistId->toString(),
                (new \App\Shared\Domain\Model\PublicId())->toString(),
                'Duplicate Test',
                'US',
                null,
                null,
                null,
                null,
                null,
                'Duplicate Test',
                null,
                null,
                null,
                null,
                '{}',
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ],
        );

        $result = $this->artistRepository->findByUuids([$artistId, $artistId]);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey($artistId->toString(), $result);
    }
}
