<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Infrastructure\Doctrine\Entity\PartyMemberEntity;
use App\Party\Infrastructure\Doctrine\Entity\SyncedPartySessionEntity;
use App\Party\Infrastructure\Doctrine\Repository\PartyMemberRepository;
use App\Party\Infrastructure\Doctrine\Repository\SyncedPartySessionRepository;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Version\Version;
use Doctrine\ORM\Tools\SchemaTool;
use DoctrineMigrations\Version20261006180000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PartyOwnershipPersistenceTest extends TestCase
{
    use OwnershipPersistenceHarness;

    private const HOST_INDEX = 'idx_party_sessions_host_user_id';

    public function testOwnersAreScalarUuidFields(): void
    {
        $this->assertScalarUuidOwners([
            PartyMemberEntity::class => ['userId', 'user_id'],
            SyncedPartySessionEntity::class => ['hostUserId', 'host_user_id'],
        ]);
    }

    public function testDeclaredForeignKeysMatchTheCatalog(): void
    {
        $this->assertDeclaredForeignKeysMatchCatalog([
            'party_members' => ['user_id', 'fk_party_members_user_id'],
            'party_sessions' => ['host_user_id', 'fk_party_sessions_host_user_id'],
        ]);
    }

    public function testSchemaComparisonIsCleanForPartyTables(): void
    {
        $this->assertSchemaComparisonIsClean(['party_members', 'party_sessions']);
    }

    public function testHostIndexIsMigratedOnceAndMapped(): void
    {
        $connection = $this->manager->getConnection();
        $definition = static fn (): string|false => $connection->fetchOne(
            'SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = :name',
            ['name' => self::HOST_INDEX],
        );

        self::assertSame(
            'CREATE INDEX ' . self::HOST_INDEX . ' ON public.party_sessions USING btree (host_user_id)',
            $definition(),
        );
        self::assertTrue((new SchemaTool($this->manager))
            ->getSchemaFromMetadata([$this->manager->getClassMetadata(SyncedPartySessionEntity::class)])
            ->getTable('party_sessions')
            ->hasIndex(self::HOST_INDEX));

        // The runner has already migrated twice; this migration is recorded and nothing remains to run.
        $migrations = $this->kernel->getContainer()->get('test.service_container')->get('doctrine.migrations.dependency_factory');
        self::assertInstanceOf(DependencyFactory::class, $migrations);
        self::assertTrue($migrations->getMetadataStorage()->getExecutedMigrations()->hasMigration(
            new Version(Version20261006180000::class),
        ));
        self::assertCount(0, $migrations->getMigrationStatusCalculator()->getNewMigrations());

        require_once dirname(__DIR__, 2) . '/migrations/Version20261006180000.php';
        $run = static function (string $direction) use ($connection): void {
            // A migration instance accumulates planned SQL, so each direction gets its own.
            $migration = new Version20261006180000($connection, new NullLogger());
            $migration->{$direction}(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        };
        $run('down');
        self::assertFalse($definition());
        $run('up');
        self::assertSame(
            'CREATE INDEX ' . self::HOST_INDEX . ' ON public.party_sessions USING btree (host_user_id)',
            $definition(),
        );
    }

    public function testMemberLookupAndHostTransferRoundTrip(): void
    {
        $host = $this->createUser();
        $guest = $this->createUser();
        $sessions = new SyncedPartySessionRepository($this->manager);
        $members = new PartyMemberRepository($this->manager);

        $session = $this->session($host);
        $sessions->save($session);
        $other = $this->session($guest);
        $sessions->save($other);
        $hostMember = PartyMember::create($host, $session->getId());
        $guestMember = PartyMember::create($guest, $session->getId());
        $guestElsewhere = PartyMember::create($guest, $other->getId());
        foreach ([$hostMember, $guestMember, $guestElsewhere] as $member) {
            $members->save($member);
        }
        $this->manager->clear();

        foreach ([[$host, $session, $hostMember], [$guest, $session, $guestMember], [$guest, $other, $guestElsewhere]] as [$user, $party, $expected]) {
            $found = $members->findByUserAndSession($user, $party->getId());
            self::assertNotNull($found);
            self::assertTrue($found->getId()->equals($expected->getId()));
            self::assertTrue($found->getUserId()->equals($user));
            self::assertTrue($found->getSessionId()->equals($party->getId()));
        }
        self::assertNull($members->findByUserAndSession($host, $other->getId()));
        self::assertSame(2, $members->countBySession($session->getId()));

        $loaded = $sessions->findByUuid($session->getId());
        self::assertNotNull($loaded);
        self::assertTrue($loaded->getHostUserId()->equals($host));

        $loaded->transferHost($guest);
        $sessions->save($loaded);
        $this->manager->clear();

        $transferred = $sessions->findByUuid($session->getId());
        self::assertNotNull($transferred);
        self::assertTrue($transferred->getHostUserId()->equals($guest));
    }

    public function testSavingAPartySessionAdvancesUpdatedAt(): void
    {
        $host = $this->createUser();
        $sessions = new SyncedPartySessionRepository($this->manager);
        $session = $this->session($host);
        $sessions->save($session);
        $connection = $this->manager->getConnection();
        $connection->executeStatement(
            "UPDATE party_sessions SET updated_at = TIMESTAMPTZ '2026-01-01 00:00:00+00' WHERE id = :id",
            ['id' => $session->getId()->toString()],
        );
        $this->manager->clear();

        $loaded = $sessions->findByUuid($session->getId());
        self::assertNotNull($loaded);
        self::assertSame('2026-01-01T00:00:00+00:00', $loaded->getUpdatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));
        $before = new \DateTimeImmutable('-1 second');

        // An unchanged aggregate still records the save.
        $sessions->save($loaded);
        $this->manager->clear();

        $saved = $sessions->findByUuid($session->getId());
        self::assertNotNull($saved);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $saved->getUpdatedAt()->getTimestamp());
    }

    public function testUserDeletionCascadesOnlyThatUsersRows(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();
        $sessions = new SyncedPartySessionRepository($this->manager);
        $members = new PartyMemberRepository($this->manager);
        foreach ([$first, $second] as $owner) {
            $session = $this->session($owner);
            $sessions->save($session);
            $members->save(PartyMember::create($owner, $session->getId()));
        }
        $this->manager->clear();

        $this->deleteUser($first);

        self::assertSame(0, $this->countOwnedRows('party_sessions', 'host_user_id', $first));
        self::assertSame(1, $this->countOwnedRows('party_sessions', 'host_user_id', $second));
        self::assertSame(0, $this->countOwnedRows('party_members', 'user_id', $first));
        self::assertSame(1, $this->countOwnedRows('party_members', 'user_id', $second));
    }

    /** @return iterable<string, array{string}> */
    public static function tables(): iterable
    {
        yield 'party_sessions' => ['party_sessions'];
        yield 'party_members' => ['party_members'];
    }

    /** @param 'party_sessions'|'party_members' $table */
    #[DataProvider('tables')]
    public function testForeignKeyRejectsAnUnknownOwner(string $table): void
    {
        $host = $this->createUser();
        $session = $this->session($host);
        (new SyncedPartySessionRepository($this->manager))->save($session);

        $this->expectException(ForeignKeyConstraintViolationException::class);

        match ($table) {
            'party_sessions' => (new SyncedPartySessionRepository($this->manager))->save($this->session(Uuid::generate())),
            'party_members' => (new PartyMemberRepository($this->manager))->save(PartyMember::create(Uuid::generate(), $session->getId())),
        };
    }

    private function session(Uuid $host): SyncedPartySession
    {
        return SyncedPartySession::create($host, Uuid::generate(), Uuid::generate());
    }
}
