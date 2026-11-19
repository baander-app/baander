<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Party\Application\Command\InvitePartyMemberCommand;
use App\Party\Application\Command\JoinPartySessionCommand;
use App\Party\Application\Command\LeavePartySessionCommand;
use App\Party\Application\Command\RevokePartyInvitationCommand;
use App\Party\Application\Command\TransferHostCommand;
use App\Party\Application\CommandHandler\InvitePartyMemberHandler;
use App\Party\Application\CommandHandler\JoinPartySessionHandler;
use App\Party\Application\CommandHandler\LeavePartySessionHandler;
use App\Party\Application\CommandHandler\RevokePartyInvitationHandler;
use App\Party\Application\CommandHandler\TransferHostHandler;
use App\Party\Application\Port\PartyInvitationPortInterface;
use App\Party\Application\Port\PartyMediaAccessPortInterface;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartyPublicIdentityPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\MemberLeft;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\MemberRole;
use App\Party\Infrastructure\Doctrine\Repository\PartyInvitationRepository;
use App\Party\Infrastructure\Doctrine\Repository\PartyMemberRepository;
use App\Party\Infrastructure\Doctrine\Repository\SyncedPartySessionRepository;
use App\Party\Infrastructure\PartyInvitationService;
use App\Party\Infrastructure\PartyMemberService;
use App\Party\Infrastructure\PartyPublicIdentity;
use App\Party\Infrastructure\PartySessionService;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\DoctrineTransaction;
use App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Changes to a party's membership take turns on the party's row, and each decides on the row as
 * it is stored once its turn comes, not as the request loaded it earlier.
 *
 * The production handlers, services and repositories run over two PostgreSQL sessions, each with
 * its own entity manager, in a disposable schema. The tables copy the migrated ones with their
 * indexes; the member table gets its foreign key to the party again, which a copy leaves out.
 */
final class PartyMembershipSerializationTest extends TestCase
{
    private Connection $first;
    private Connection $second;
    private EntityManager $manager;
    private EntityManager $competitor;
    private string $schema;

    /** @var list<object> the events the handlers dispatched */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to a disposable PostgreSQL database.');
        }

        CustomTypesRegistrar::register();
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $dbal = new Configuration();
        $dbal->setMiddlewares([new BaanderDriverMiddleware()]);
        $this->first = DriverManager::getConnection($params, $dbal);
        $this->second = DriverManager::getConnection($params, $dbal);
        $this->schema = 'party_membership_' . bin2hex(random_bytes(8));
        $this->first->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->first, $this->second] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
        }
        foreach (['users', 'party_sessions', 'party_members', 'party_invitations'] as $table) {
            $this->first->executeStatement('CREATE TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL)');
        }
        $this->first->executeStatement('ALTER TABLE party_members ADD FOREIGN KEY (session_id) REFERENCES party_sessions (id) ON DELETE CASCADE');

        $this->manager = $this->entityManager($this->first);
        $this->competitor = $this->entityManager($this->second);
    }

    protected function tearDown(): void
    {
        $connections = array_filter([$this->second ?? null, $this->first ?? null]);
        foreach ($connections as $connection) {
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        if (isset($this->first, $this->schema)) {
            $this->first->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
        }
        foreach ($connections as $connection) {
            $connection->close();
        }
    }

    public function testALeaveAndAHostTransferFailOnTheHeldPartyLockAndChangeNothing(): void
    {
        [$party, [$host, $guest]] = $this->party(guests: 1);
        $before = $this->stored($party);

        // Another change of the same party is under way in the second session.
        $this->second->beginTransaction();
        self::assertNotNull($this->sessions($this->competitor)->lockByUuid($party));
        $this->first->executeStatement("SET lock_timeout = '200ms'");

        $changes = [
            'leave' => fn () => $this->leave($this->manager, $party, $guest),
            'host transfer' => fn () => $this->transferHost($this->manager, $party, $host, $guest),
        ];
        foreach ($changes as $name => $change) {
            try {
                $change();
                self::fail('The ' . $name . ' did not wait for the party\'s row lock.');
            } catch (DriverException $refused) {
                self::assertSame('55P03', $refused->getSQLState(), 'The ' . $name . ' ends on the lock timeout.');
            }
        }
        $this->second->rollBack();

        self::assertSame($before, $this->stored($party));
        self::assertSame([], $this->dispatched);
    }

    public function testAnInviteAndARevokeFailOnTheHeldPartyLockAndChangeNothing(): void
    {
        [$party, [$host, $guest, $uninvited]] = $this->party(guests: 2);
        // The guest holds an invitation and a member row for the revoke to delete; the third
        // user holds no invitation for the invite to write.
        $this->invitations($this->manager)->invite($guest->getId(), $party);
        $this->manager->clear();
        $before = [$this->stored($party), $this->invited($party)];
        self::assertSame([$guest->getId()->toString()], $before[1]);

        // Another change of the same party is under way in the second session.
        $this->second->beginTransaction();
        self::assertNotNull($this->sessions($this->competitor)->lockByUuid($party));
        $this->first->executeStatement("SET lock_timeout = '200ms'");

        $changes = [
            'invite' => fn () => $this->invite($this->manager, $party, $host, $uninvited),
            'revoke' => fn () => $this->revoke($this->manager, $party, $host, $guest),
        ];
        foreach ($changes as $name => $change) {
            try {
                $change();
                self::fail('The ' . $name . ' did not wait for the party\'s row lock.');
            } catch (DriverException $refused) {
                self::assertSame('55P03', $refused->getSQLState(), 'The ' . $name . ' ends on the lock timeout.');
            }
        }
        $this->second->rollBack();

        self::assertSame($before, [$this->stored($party), $this->invited($party)]);
        self::assertSame([], $this->dispatched);
    }

    public function testAJoinFailsOnTheHeldPartyLockAndAddsNoMember(): void
    {
        [$party, [, $member]] = $this->party(guests: 1);
        // One joiner returns to the member row they hold, which writes nothing that refers to
        // the party; the other holds an invitation and no member row yet.
        $invited = $this->user();
        $this->manager->flush();
        $this->invitations($this->manager)->invite($invited->getId(), $party);
        $this->manager->clear();
        $before = [$this->stored($party), $this->invited($party)];
        self::assertSame([$invited->getId()->toString()], $before[1]);

        // Another change of the same party is under way in the second session.
        $this->second->beginTransaction();
        self::assertNotNull($this->sessions($this->competitor)->lockByUuid($party));
        $this->first->executeStatement("SET lock_timeout = '200ms'");

        foreach (['returning member' => $member, 'invited user' => $invited] as $name => $joiner) {
            try {
                $this->join($this->manager, $party, $joiner);
                self::fail('The join of the ' . $name . ' did not wait for the party\'s row lock.');
            } catch (DriverException $refused) {
                self::assertSame('55P03', $refused->getSQLState(), 'The join of the ' . $name . ' ends on the lock timeout.');
            }
        }
        $this->second->rollBack();

        self::assertSame($before, [$this->stored($party), $this->invited($party)]);
        self::assertSame([], $this->dispatched);
    }

    public function testALeaveDecidesOnTheHostACompetingTransferCommittedAfterTheSessionWasLoaded(): void
    {
        [$party, [$host, $guest, $latest]] = $this->party(guests: 2);

        // A route loads the session before it dispatches; the entity stays in this manager.
        $loaded = $this->sessions($this->manager)->findByUuid($party);
        self::assertTrue($loaded?->getHostUserId()->equals($host->getId()));

        $this->transferHost($this->competitor, $party, $host, $guest);
        self::assertSame($guest->getId()->toString(), $this->stored($party)['host']);
        self::assertTrue(
            $this->sessions($this->manager)->findByUuid($party)?->getHostUserId()->equals($host->getId()),
            'The first manager still holds the session as it was before the transfer.',
        );

        $this->leave($this->manager, $party, $guest);

        self::assertSame(
            [
                'host' => $host->getId()->toString(),
                'active' => true,
                'members' => [
                    $host->getId()->toString() => MemberRole::Host->value,
                    $latest->getId()->toString() => MemberRole::Member->value,
                ],
            ],
            $this->stored($party),
            'The new host left, so the earliest-joined remaining member hosts the party and holds the host role.',
        );
        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(MemberLeft::class, $this->dispatched[0]);
        self::assertTrue($this->dispatched[0]->getUserId()->equals($guest->getId()));
    }

    public function testAnOnwardTransferDemotesACallerWhoseMemberRowWasLoadedBeforeTheyBecameHost(): void
    {
        [$party, [$host, $caller, $third]] = $this->party(guests: 2);

        // A route's membership check loads the caller's member row before it dispatches.
        $loaded = $this->members($this->manager)->findByUserAndSession($caller->getId(), $party);
        self::assertSame(MemberRole::Member, $loaded?->getRole());

        $this->transferHost($this->competitor, $party, $host, $caller);
        self::assertSame(MemberRole::Host->value, $this->stored($party)['members'][$caller->getId()->toString()]);

        $this->transferHost($this->manager, $party, $caller, $third);

        self::assertSame(
            [
                'host' => $third->getId()->toString(),
                'active' => true,
                'members' => [
                    $host->getId()->toString() => MemberRole::Member->value,
                    $caller->getId()->toString() => MemberRole::Member->value,
                    $third->getId()->toString() => MemberRole::Host->value,
                ],
            ],
            $this->stored($party),
            'The session names the third member as host, and only that member holds the host role.',
        );
    }

    public function testALeavePromotesAMemberWhoseRowWasLoadedBeforeACompetingTransferDemotedThem(): void
    {
        [$party, [$host, $guest]] = $this->party(guests: 1);

        // Loaded while the first member still hosted the party.
        self::assertCount(2, $this->members($this->manager)->findBySession($party));

        $this->transferHost($this->competitor, $party, $host, $guest);
        self::assertSame(MemberRole::Member->value, $this->stored($party)['members'][$host->getId()->toString()]);

        $this->leave($this->manager, $party, $guest);

        self::assertSame(
            [
                'host' => $host->getId()->toString(),
                'active' => true,
                'members' => [$host->getId()->toString() => MemberRole::Host->value],
            ],
            $this->stored($party),
            'The remaining member hosts the party again and holds the host role again.',
        );
    }

    public function testThePartyLockIsRefusedOutsideATransaction(): void
    {
        [$party] = $this->party(guests: 0);

        // Without a transaction the lock would end with its statement and order nothing.
        $this->expectException(TransactionRequiredException::class);

        $this->sessions($this->manager)->lockByUuid($party);
    }

    private function join(EntityManager $manager, Uuid $party, UserEntity $user): void
    {
        $handler = new JoinPartySessionHandler(
            $this->sessions($manager),
            $this->members($manager),
            $this->invitations($manager),
            $this->transaction($manager),
            $this->events(),
        );

        $handler(new JoinPartySessionCommand($user->getId(), $party));
    }

    private function leave(EntityManager $manager, Uuid $party, UserEntity $user): void
    {
        $handler = new LeavePartySessionHandler(
            $this->sessions($manager),
            $this->members($manager),
            $this->transaction($manager),
            $this->events(),
        );

        $handler(new LeavePartySessionCommand($user->getId(), $party));
    }

    private function transferHost(EntityManager $manager, Uuid $party, UserEntity $host, UserEntity $target): void
    {
        $handler = new TransferHostHandler(
            $this->sessions($manager),
            $this->members($manager),
            $this->identities($manager),
            $this->transaction($manager),
        );

        $handler(new TransferHostCommand($party, $host->getId(), $target->getPublicId()));
    }

    private function invite(EntityManager $manager, Uuid $party, UserEntity $host, UserEntity $user): void
    {
        $handler = new InvitePartyMemberHandler(
            $this->sessions($manager),
            $this->invitations($manager),
            $this->identities($manager),
            $this->transaction($manager),
        );

        $handler(new InvitePartyMemberCommand($party, $host->getId(), $user->getPublicId()));
    }

    private function revoke(EntityManager $manager, Uuid $party, UserEntity $host, UserEntity $user): void
    {
        $handler = new RevokePartyInvitationHandler(
            $this->sessions($manager),
            $this->invitations($manager),
            $this->members($manager),
            $this->identities($manager),
            $this->transaction($manager),
            $this->events(),
        );

        $handler(new RevokePartyInvitationCommand($party, $host->getId(), $user->getPublicId()));
    }

    private function sessions(EntityManager $manager): PartySessionPortInterface
    {
        return new PartySessionService(new SyncedPartySessionRepository($manager));
    }

    private function members(EntityManager $manager): PartyMemberPortInterface
    {
        return new PartyMemberService(new PartyMemberRepository($manager));
    }

    private function invitations(EntityManager $manager): PartyInvitationPortInterface
    {
        return new PartyInvitationService(new PartyInvitationRepository($manager));
    }

    private function identities(EntityManager $manager): PartyPublicIdentityPortInterface
    {
        return new PartyPublicIdentity($manager->getConnection(), $this->createStub(PartyMediaAccessPortInterface::class));
    }

    private function transaction(EntityManager $manager): TransactionPortInterface
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $registry->method('resetManager')->willReturn($manager);

        return new DoctrineTransaction($registry, new Swoole());
    }

    private function events(): EventDispatcherInterface
    {
        $events = $this->createStub(EventDispatcherInterface::class);
        $events->method('dispatch')->willReturnCallback(function (object $event): object {
            $this->dispatched[] = $event;

            return $event;
        });

        return $events;
    }

    /**
     * A committed party whose host and guests hold member rows, joined a minute apart in that
     * order. Neither manager holds any of it afterwards.
     *
     * @return array{Uuid, list<UserEntity>} the party's ID and its members in joining order, the host first
     */
    private function party(int $guests): array
    {
        $users = [];
        for ($index = 0; $index <= $guests; ++$index) {
            $users[] = $this->user();
        }
        $this->manager->flush();

        $session = SyncedPartySession::create($users[0]->getId(), Uuid::generate(), null);
        (new SyncedPartySessionRepository($this->manager))->save($session);
        foreach ($users as $index => $user) {
            $member = PartyMember::create($user->getId(), $session->getId(), $index === 0 ? MemberRole::Host : MemberRole::Member);
            (new PartyMemberRepository($this->manager))->save($member);
            $this->first->executeStatement(
                "UPDATE party_members SET joined_at = TIMESTAMPTZ '2026-01-01 10:00:00+00' + make_interval(mins => :minutes) WHERE id = :id",
                ['minutes' => $index, 'id' => $member->getId()->toString()],
            );
        }
        $this->manager->clear();

        return [$session->getId(), $users];
    }

    /** A user the first manager stores with its next flush. */
    private function user(): UserEntity
    {
        $user = new UserEntity(new PublicId(), 'Party member', bin2hex(random_bytes(8)) . '@baander.app', 'test-only');
        $this->manager->persist($user);

        return $user;
    }

    /**
     * The party as the second session reads it: what is committed.
     *
     * @return array{host: string, active: bool, members: array<string, string>} the members' roles by user ID, in joining order
     */
    private function stored(Uuid $party): array
    {
        $session = $this->second->fetchAssociative(
            'SELECT host_user_id, is_active FROM party_sessions WHERE id = :id',
            ['id' => $party->toString()],
        );
        self::assertIsArray($session);

        return [
            'host' => (string) $session['host_user_id'],
            'active' => (bool) $session['is_active'],
            'members' => $this->second->fetchAllKeyValue(
                'SELECT user_id, role FROM party_members WHERE session_id = :id ORDER BY joined_at',
                ['id' => $party->toString()],
            ),
        ];
    }

    /**
     * The party's invitations as the second session reads them: what is committed.
     *
     * @return list<string> the invited users' IDs, oldest invitation first
     */
    private function invited(Uuid $party): array
    {
        return $this->second->fetchFirstColumn(
            'SELECT user_id FROM party_invitations WHERE session_id = :id ORDER BY created_at, id',
            ['id' => $party->toString()],
        );
    }

    private function entityManager(Connection $connection): EntityManager
    {
        $orm = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src'], isDevMode: true);
        $orm->setNamingStrategy(new UnderscoreNamingStrategy());
        $orm->enableNativeLazyObjects(true);

        return new EntityManager($connection, $orm);
    }
}
