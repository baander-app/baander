<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Repository\AlbumMatchStateRepository;
use App\Kernel;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Support\Catalog\AlbumMatchStatesContractCases;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The album match queue's statements on PostgreSQL pass the cases the in-memory album match
 * states pass. Each album is a stored row, since a match state belongs to one, and a claim's
 * expiry is read against the database clock. Every case runs in a transaction that is rolled back.
 */
final class AlbumMatchStatesContractTest extends AlbumMatchStatesContractCases
{
    private Kernel $kernel;
    private Connection $connection;
    private AlbumRepositoryInterface $albums;
    private AlbumMatchStateRepository $states;
    private ?Uuid $library = null;

    protected function setUp(): void
    {
        // The shared cases are a plain PHPUnit case class, so that the in-memory subclass needs
        // no kernel; this subclass boots its own.
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $manager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $albums = $container->get(AlbumRepositoryInterface::class);
        self::assertInstanceOf(AlbumRepositoryInterface::class, $albums);

        $this->connection = $manager->getConnection();
        $this->albums = $albums;
        $this->states = new AlbumMatchStateRepository($this->connection);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    #[\Override]
    protected function states(): AlbumMatchStateRepositoryInterface
    {
        return $this->states;
    }

    #[\Override]
    protected function album(): Uuid
    {
        $album = Album::create($this->library ??= $this->library(), 'F-1 Trillion: Long Bed', 'album');
        $this->albums->save($album);

        return $album->getId();
    }

    #[\Override]
    protected function storeClock(): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $this->connection->fetchOne('SELECT clock_timestamp()'));
    }

    #[\Override]
    protected function lapseClaims(): void
    {
        // The database clock cannot be moved, so each claim's expiry moves into the past instead.
        $this->connection->executeStatement(
            "UPDATE album_match_states SET claim_expires_at = clock_timestamp() - interval '1 second' WHERE claim_id IS NOT NULL",
        );
    }

    private function library(): Uuid
    {
        $library = Uuid::v7();
        $this->connection->executeStatement(
            "INSERT INTO libraries (id, public_id, slug, name, path, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, 'Country', ?, 'music', 0, clock_timestamp(), clock_timestamp())",
            [$library->toString(), new PublicId()->toString(), 'country-' . (random_bytes(4) |> bin2hex(...)), '/country-' . (random_bytes(4) |> bin2hex(...))],
        );

        return $library;
    }
}
