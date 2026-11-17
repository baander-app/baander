<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Party\Application\Port\PartyInvitationPortInterface;
use App\Party\Domain\Model\PartyInvitation;
use App\Party\Domain\Model\PartyInvitationState;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Infrastructure\Doctrine\Entity\PartyInvitationEntity;
use App\Party\Infrastructure\Doctrine\Entity\PartyMemberEntity;
use App\Party\Infrastructure\Doctrine\Entity\SyncedPartySessionEntity;
use App\Party\Infrastructure\Doctrine\Repository\PartyInvitationRepository;
use App\Party\Infrastructure\Doctrine\Repository\PartyMemberRepository;
use App\Party\Infrastructure\Doctrine\Repository\SyncedPartySessionRepository;
use App\Party\Infrastructure\PartyInvitationService;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PartyOwnershipPersistenceTest extends TestCase
{
    use OwnershipPersistenceHarness;

    private const HOST_INDEX = 'idx_party_sessions_host_user_id';

    public function testOwnersAreScalarUuidFields(): void
    {
        $this->assertScalarUuidOwners([
            PartyInvitationEntity::class => ['userId', 'user_id'],
            PartyMemberEntity::class => ['userId', 'user_id'],
            SyncedPartySessionEntity::class => ['hostUserId', 'host_user_id'],
        ]);
    }

    public function testDeclaredForeignKeysMatchTheCatalog(): void
    {
        $this->assertDeclaredForeignKeysMatchCatalog([
            'party_invitations' => ['user_id', 'fk_party_invitations_user_id'],
            'party_members' => ['user_id', 'fk_party_members_user_id'],
            'party_sessions' => ['host_user_id', 'fk_party_sessions_host_user_id'],
        ]);
    }

    public function testSchemaComparisonIsCleanForPartyTables(): void
    {
        $this->assertSchemaComparisonIsClean(['party_invitations', 'party_members', 'party_sessions']);
    }

    public function testInvitationTableHasItsColumnsNamedKeysAndIndexes(): void
    {
        $connection = $this->manager->getConnection();

        self::assertSame([
            ['id', 'uuid', 'NO', null],
            ['session_id', 'uuid', 'NO', null],
            ['user_id', 'uuid', 'NO', null],
            ['created_at', 'timestamp with time zone', 'NO', null],
        ], $connection->fetchAllNumeric(<<<'SQL'
            SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = 'party_invitations'
             ORDER BY ordinal_position
            SQL));
        self::assertSame([
            'fk_party_invitations_session_id' => 'FOREIGN KEY (session_id) REFERENCES party_sessions(id) ON DELETE CASCADE',
            'fk_party_invitations_user_id' => 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
            'party_invitations_pkey' => 'PRIMARY KEY (id)',
        ], $connection->fetchAllKeyValue(<<<'SQL'
            SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint
             WHERE conrelid = 'party_invitations'::regclass AND contype IN ('p', 'f', 'u', 'c', 'x')
             ORDER BY conname COLLATE "C"
            SQL));
        self::assertSame([
            'idx_party_invitations_session_id' => 'CREATE INDEX idx_party_invitations_session_id ON public.party_invitations USING btree (session_id)',
            'party_invitations_pkey' => 'CREATE UNIQUE INDEX party_invitations_pkey ON public.party_invitations USING btree (id)',
            'uniq_party_invitations_user_id_session_id' => 'CREATE UNIQUE INDEX uniq_party_invitations_user_id_session_id ON public.party_invitations USING btree (user_id, session_id)',
        ], $connection->fetchAllKeyValue(<<<'SQL'
            SELECT indexname, indexdef FROM pg_indexes
             WHERE schemaname = current_schema() AND tablename = 'party_invitations'
             ORDER BY indexname COLLATE "C"
            SQL));
    }

    public function testHostIndexExistsAndIsMapped(): void
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

    public function testAnInvitationIsFoundAfterAReloadAndDeleted(): void
    {
        $host = $this->createUser();
        $guest = $this->createUser();
        $sessions = new SyncedPartySessionRepository($this->manager);
        $invitations = new PartyInvitationRepository($this->manager);
        $session = $this->session($host);
        $sessions->save($session);
        $other = $this->session($host);
        $sessions->save($other);
        $issued = PartyInvitation::issue($guest, $session->getId());
        $invitations->save($issued);
        $this->manager->clear();

        $found = $invitations->findByUserAndSession($guest, $session->getId());
        self::assertNotNull($found);
        self::assertTrue($found->getId()->equals($issued->getId()));
        self::assertTrue($found->getUserId()->equals($guest));
        self::assertTrue($found->getSessionId()->equals($session->getId()));
        self::assertSame($issued->getCreatedAt()->format('U.u'), $found->getCreatedAt()->format('U.u'));
        self::assertNull($invitations->findByUserAndSession($host, $session->getId()));
        self::assertNull($invitations->findByUserAndSession($guest, $other->getId()));

        // An invitation never changes, so saving a stored one writes nothing.
        $invitations->save($found);
        self::assertSame(1, $this->countOwnedRows('party_invitations', 'session_id', $session->getId()));

        $invitations->delete($found);
        $this->manager->clear();

        self::assertNull($invitations->findByUserAndSession($guest, $session->getId()));
        self::assertSame(0, $this->countOwnedRows('party_invitations', 'session_id', $session->getId()));
    }

    public function testInvitingTwiceKeepsTheFirstInvitationAndItsCreationTime(): void
    {
        $guest = $this->createUser();
        $session = $this->savedSession($this->createUser());
        $port = $this->invitationPort();

        $port->invite($guest, $session);
        $this->manager->getConnection()->executeStatement(
            "UPDATE party_invitations SET created_at = TIMESTAMPTZ '2026-01-01 00:00:00.250000+00' WHERE session_id = :session",
            ['session' => $session->toString()],
        );
        $first = $port->findBySession($session);
        self::assertCount(1, $first);
        $this->manager->clear();

        $port->invite($guest, $session);
        $this->manager->clear();

        $stored = $port->findBySession($session);
        self::assertCount(1, $stored);
        self::assertTrue($stored[0]->getId()->equals($first[0]->getId()));
        self::assertTrue($stored[0]->getUserId()->equals($guest));
        self::assertSame('2026-01-01T00:00:00.250000+00:00', $stored[0]->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'));
    }

    public function testRevokingDeletesTheInvitationAndReportsWhetherOneExisted(): void
    {
        $guest = $this->createUser();
        $bystander = $this->createUser();
        $session = $this->savedSession($this->createUser());
        $port = $this->invitationPort();
        $port->invite($guest, $session);
        $port->invite($bystander, $session);
        $this->manager->clear();

        self::assertTrue($port->isInvited($guest, $session));
        self::assertTrue($port->revoke($guest, $session));
        self::assertFalse($port->isInvited($guest, $session));
        self::assertFalse($port->revoke($guest, $session));
        self::assertTrue($port->isInvited($bystander, $session));
        self::assertSame(0, $this->countOwnedRows('party_invitations', 'user_id', $guest));

        // A revoked user can be invited again.
        $port->invite($guest, $session);
        self::assertTrue($port->isInvited($guest, $session));
    }

    public function testWhetherAUserIsInvitedIsReadFromTheStoredRow(): void
    {
        $guest = $this->createUser();
        $session = $this->savedSession($this->createUser());
        $port = $this->invitationPort();
        $port->invite($guest, $session);
        // The invitation stays loaded while its row is deleted behind it, as a cascade or another request would.
        self::assertCount(1, $port->findBySession($session));
        $this->manager->getConnection()->executeStatement(
            'DELETE FROM party_invitations WHERE user_id = :user',
            ['user' => $guest->toString()],
        );

        self::assertFalse($port->isInvited($guest, $session));
    }

    public function testInvitationsOfAPartyAreListedOldestFirstThenByIdentity(): void
    {
        $host = $this->createUser();
        $session = $this->savedSession($host);
        $elsewhere = $this->savedSession($host);
        $invitations = new PartyInvitationRepository($this->manager);
        // Saved newest first. The oldest has the highest identity, and of the two that share
        // a creation time the higher identity is saved first.
        foreach ([
            ['0199bf3c-8a00-7000-8000-000000000001', $session, '12:00:02.000000'],
            ['0199bf3c-8a00-7000-8000-000000000003', $session, '12:00:01.500000'],
            ['0199bf3c-8a00-7000-8000-000000000002', $session, '12:00:01.500000'],
            ['0199bf3c-8a00-7000-8000-000000000009', $session, '12:00:01.499999'],
            ['0199bf3c-8a00-7000-8000-000000000000', $elsewhere, '12:00:00.000000'],
        ] as [$id, $party, $time]) {
            $invitations->save(PartyInvitation::reconstitute(new PartyInvitationState(
                id: Uuid::fromString($id),
                userId: $this->createUser(),
                sessionId: $party,
                createdAt: new \DateTimeImmutable('2026-11-17 ' . $time . '+00:00'),
            )));
        }
        $this->manager->clear();

        self::assertSame([
            '0199bf3c-8a00-7000-8000-000000000009',
            '0199bf3c-8a00-7000-8000-000000000002',
            '0199bf3c-8a00-7000-8000-000000000003',
            '0199bf3c-8a00-7000-8000-000000000001',
        ], array_map(
            static fn (PartyInvitation $invitation): string => $invitation->getId()->toString(),
            $this->invitationPort()->findBySession($session),
        ));
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

    public function testDeletingAUserRemovesTheirInvitationsAndKeepsTheParty(): void
    {
        $deleted = $this->createUser();
        $kept = $this->createUser();
        $session = $this->savedSession($this->createUser());
        $port = $this->invitationPort();
        $port->invite($deleted, $session);
        $port->invite($kept, $session);
        $this->manager->clear();

        $this->deleteUser($deleted);

        self::assertSame(0, $this->countOwnedRows('party_invitations', 'user_id', $deleted));
        self::assertSame(1, $this->countOwnedRows('party_invitations', 'user_id', $kept));
        self::assertSame(1, $this->countOwnedRows('party_sessions', 'id', $session));
    }

    public function testRemovingAPartySessionRemovesItsInvitationsAndLaterWritesStillFlush(): void
    {
        $host = $this->createUser();
        $guest = $this->createUser();
        $sessions = new SyncedPartySessionRepository($this->manager);
        $removed = $this->session($host);
        $sessions->save($removed);
        $kept = $this->savedSession($host);
        $port = $this->invitationPort();
        $port->invite($guest, $removed->getId());
        $port->invite($guest, $kept);
        // The removed party's invitation is still loaded when the database deletes its row with the party.
        self::assertCount(1, $port->findBySession($removed->getId()));

        $sessions->delete($removed);
        $this->createUser();

        self::assertSame(0, $this->countOwnedRows('party_invitations', 'session_id', $removed->getId()));
        self::assertSame(1, $this->countOwnedRows('party_invitations', 'session_id', $kept));
    }

    /** @return iterable<string, array{string}> */
    public static function tables(): iterable
    {
        yield 'party_sessions' => ['party_sessions'];
        yield 'party_members' => ['party_members'];
        yield 'party_invitations' => ['party_invitations'];
    }

    /** @param 'party_sessions'|'party_members'|'party_invitations' $table */
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
            'party_invitations' => (new PartyInvitationRepository($this->manager))->save(PartyInvitation::issue(Uuid::generate(), $session->getId())),
        };
    }

    private function session(Uuid $host): SyncedPartySession
    {
        return SyncedPartySession::create($host, $this->createVideo(), null);
    }

    /** The ID of a stored party hosted by $host. */
    private function savedSession(Uuid $host): Uuid
    {
        $session = $this->session($host);
        (new SyncedPartySessionRepository($this->manager))->save($session);

        return $session->getId();
    }

    private function invitationPort(): PartyInvitationPortInterface
    {
        return new PartyInvitationService(new PartyInvitationRepository($this->manager));
    }
}
