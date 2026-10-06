<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Session\Domain\Model\Device\Device;
use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Infrastructure\Doctrine\Entity\DeviceEntity;
use App\Session\Infrastructure\Doctrine\Entity\ListeningSessionEntity;
use App\Session\Infrastructure\Doctrine\Repository\DeviceDoctrineRepository;
use App\Session\Infrastructure\Doctrine\Repository\ListeningSessionDoctrineRepository;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionOwnershipPersistenceTest extends TestCase
{
    use OwnershipPersistenceHarness;

    /** @var array<string, array{class-string, string}> table => [entity, migration-defined FK name] */
    private const TABLES = [
        'devices' => [DeviceEntity::class, 'fk_devices_user_id'],
        'listening_sessions' => [ListeningSessionEntity::class, 'fk_listening_sessions_user_id'],
    ];

    public function testOwnersAreScalarUuidFields(): void
    {
        $this->assertScalarUuidOwners([
            DeviceEntity::class => ['userId', 'user_id'],
            ListeningSessionEntity::class => ['userId', 'user_id'],
        ]);
    }

    public function testDeclaredForeignKeysMatchTheCatalog(): void
    {
        $this->assertDeclaredForeignKeysMatchCatalog(array_map(
            static fn (array $table): array => ['user_id', $table[1]],
            self::TABLES,
        ));
    }

    public function testSchemaComparisonIsCleanForSessionTables(): void
    {
        $this->assertSchemaComparisonIsClean(array_keys(self::TABLES));
    }

    public function testDeviceListingIsOwnerScopedAndOrderedByLastSeen(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();
        $repository = new DeviceDoctrineRepository($this->manager);
        $connection = $this->manager->getConnection();

        // Interleave owners and insertion order so neither owner nor insertion order explains the result.
        $seen = [];
        foreach ([[$first, 'a-old', 10], [$second, 'b-new', 40], [$first, 'a-new', 50], [$second, 'b-old', 5], [$first, 'a-mid', 30]] as [$owner, $name, $offset]) {
            $device = Device::create($owner, Uuid::generate(), $name);
            $repository->save($device);
            $seen[$name] = $device->getDeviceId();
            $connection->executeStatement(
                "UPDATE devices SET last_seen_at = TIMESTAMPTZ '2026-10-06 12:00:00+00' + make_interval(mins => :offset) WHERE id = :id",
                ['offset' => $offset, 'id' => $device->getId()->toString()],
            );
        }
        $this->manager->clear();

        $names = static fn (array $devices): array => array_map(static fn (Device $device): ?string => $device->getName(), $devices);
        self::assertSame(['a-new', 'a-mid', 'a-old'], $names($repository->findByUserId($first)));
        self::assertSame(['b-new', 'b-old'], $names($repository->findByUserId($second)));

        $found = $repository->findByUserAndDevice($first, $seen['a-mid']);
        self::assertNotNull($found);
        self::assertTrue($found->getUserId()->equals($first));
        self::assertSame('a-mid', $found->getName());
        self::assertNull($repository->findByUserAndDevice($second, $seen['a-mid']));
    }

    public function testListeningSessionIsFoundByItsOwner(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();
        $repository = new ListeningSessionDoctrineRepository($this->manager);

        $ids = [];
        foreach ([$first, $second] as $index => $owner) {
            $session = ListeningSession::create($owner, ['track-' . $index], 0, 1.5);
            $session->claim(Uuid::generate());
            $repository->save($session);
            $ids[$owner->toString()] = $session->getId();
        }
        $this->manager->clear();

        foreach ([$first, $second] as $index => $owner) {
            $found = $repository->findByUserId($owner);
            self::assertNotNull($found);
            self::assertTrue($found->getId()->equals($ids[$owner->toString()]));
            self::assertTrue($found->getUserId()->equals($owner));
            self::assertSame(['track-' . $index], $found->getQueue());
        }
    }

    public function testUserDeletionCascadesOnlyThatUsersRows(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();
        foreach ([$first, $second] as $owner) {
            (new DeviceDoctrineRepository($this->manager))->save(Device::create($owner, Uuid::generate(), 'Phone'));
            (new ListeningSessionDoctrineRepository($this->manager))->save(ListeningSession::create($owner, [], 0, 0.0));
        }
        $this->manager->clear();

        $this->deleteUser($first);

        foreach (array_keys(self::TABLES) as $table) {
            self::assertSame(0, $this->countOwnedRows($table, 'user_id', $first), $table);
            self::assertSame(1, $this->countOwnedRows($table, 'user_id', $second), $table);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function tables(): iterable
    {
        foreach (array_keys(self::TABLES) as $table) {
            yield $table => [$table];
        }
    }

    /** @param key-of<self::TABLES> $table */
    #[DataProvider('tables')]
    public function testForeignKeyRejectsAnUnknownOwner(string $table): void
    {
        $unknown = Uuid::generate();

        $this->expectException(ForeignKeyConstraintViolationException::class);

        match ($table) {
            'devices' => (new DeviceDoctrineRepository($this->manager))->save(Device::create($unknown, Uuid::generate(), 'Phone')),
            'listening_sessions' => (new ListeningSessionDoctrineRepository($this->manager))->save(ListeningSession::create($unknown, [], 0, 0.0)),
        };
    }
}
