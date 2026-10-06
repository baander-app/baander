<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Kernel;
use App\Notification\Application\DTO\PushSubscriptionRegistration;
use App\Notification\Domain\Model\Notification;
use App\Notification\Domain\Model\NotificationPreference;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Domain\ValueObject\NotificationChannel;
use App\Notification\Infrastructure\Doctrine\Entity\NotificationEntity;
use App\Notification\Infrastructure\Doctrine\Entity\NotificationPreferenceEntity;
use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Notification\Infrastructure\Doctrine\Repository\NotificationPreferenceRepository;
use App\Notification\Infrastructure\Doctrine\Repository\NotificationRepository;
use App\Notification\Infrastructure\Push\PushSubscriptionRepository;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DoctrineMigrations\Version20261006170000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Production mappings, migrated constraints and repositories, on disposable PostgreSQL only. */
final class NotificationOwnershipPersistenceTest extends TestCase
{
    /** @var array<string, array{class-string, string}> table => [entity, migration-defined FK name] */
    private const ENTITIES = [
        'notifications' => [NotificationEntity::class, 'fk_notifications_user_id'],
        'notification_preferences' => [NotificationPreferenceEntity::class, 'fk_notification_preferences_user_id'],
        'push_subscriptions' => [PushSubscriptionEntity::class, 'fk_push_subscriptions_user_id'],
    ];

    private Kernel $kernel;
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Requires fully migrated disposable PostgreSQL.');
        }

        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();

        $manager = $this->kernel->getContainer()->get('test.service_container')->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        $this->manager = $manager;
        $this->manager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->manager)) {
            $this->manager->getConnection()->rollBack();
            $this->manager->clear();
        }

        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    public function testOwnersAreScalarUuidFields(): void
    {
        foreach (self::ENTITIES as [$entity]) {
            $metadata = $this->manager->getClassMetadata($entity);
            self::assertTrue($metadata->hasField('userId'), $entity);
            self::assertFalse($metadata->hasAssociation('user'), $entity);
            self::assertSame('user_id', $metadata->getColumnName('userId'), $entity);
            self::assertSame('uuid', $metadata->getTypeOfField('userId'), $entity);
        }
    }

    public function testDeclaredForeignKeysMatchTheCatalog(): void
    {
        $tool = new SchemaTool($this->manager);
        $schema = $tool->getSchemaFromMetadata($this->manager->getMetadataFactory()->getAllMetadata());
        $catalog = $this->manager->getConnection()->createSchemaManager();

        foreach (self::ENTITIES as $table => [, $name]) {
            $expected = $schema->getTable($table)->getForeignKey($name);
            self::assertSame(['user_id'], $expected->getLocalColumns(), $table);
            self::assertSame('users', $expected->getForeignTableName(), $table);
            self::assertSame(['id'], $expected->getForeignColumns(), $table);
            self::assertSame('CASCADE', $expected->onDelete(), $table);

            $actual = $catalog->introspectTable($table)->getForeignKey($name);
            self::assertSame($actual->getLocalColumns(), $expected->getLocalColumns(), $table);
            self::assertSame($actual->getForeignTableName(), $expected->getForeignTableName(), $table);
            self::assertSame($actual->getForeignColumns(), $expected->getForeignColumns(), $table);
            self::assertSame($actual->onDelete(), $expected->onDelete(), $table);
        }

        // The producer drill builds this table alone; it must keep the users-table contract.
        $partial = $tool->getSchemaFromMetadata([$this->manager->getClassMetadata(NotificationPreferenceEntity::class)]);
        self::assertSame(
            'CASCADE',
            $partial->getTable('notification_preferences')->getForeignKey('fk_notification_preferences_user_id')->onDelete(),
        );
    }

    public function testSchemaComparisonIsCleanForNotificationTables(): void
    {
        $tool = new SchemaTool($this->manager);
        $metadata = $this->manager->getMetadataFactory()->getAllMetadata();
        $catalog = $this->manager->getConnection()->createSchemaManager();
        $mapped = $tool->getSchemaFromMetadata($metadata);

        // PostgreSQL's DROP INDEX names only the index, so match every asset on these tables,
        // including DBAL's implicit foreign-key index names on either side of the comparison.
        $names = [];
        foreach (array_keys(self::ENTITIES) as $table) {
            $names[] = $table;
            foreach ([$catalog->introspectTable($table), $mapped->getTable($table)] as $side) {
                foreach ($side->getIndexes() as $index) {
                    $names[] = $index->getName();
                }
                foreach ($side->getForeignKeys() as $foreignKey) {
                    $names[] = $foreignKey->getName();
                }
            }
        }
        $pattern = '/\b(' . implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), array_unique($names))) . ')\b/i';

        $statements = array_values(array_filter(
            $tool->getUpdateSchemaSql($metadata),
            static fn (string $sql): bool => preg_match($pattern, $sql) === 1,
        ));

        self::assertSame([], $statements);
    }

    public function testRepositoriesIsolateOwnersAndUserDeletionCascades(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();
        $notifications = new NotificationRepository($this->manager);
        $preferences = new NotificationPreferenceRepository($this->manager);
        $subscriptions = new PushSubscriptionRepository($this->manager);

        foreach ([$first, $second] as $userId) {
            $notifications->save($this->notification($userId, 'owned'));
            $preferences->save(NotificationPreference::create($userId, NotificationCategory::Security, NotificationChannel::Email, false));
            $subscriptions->registerForUser($userId, new PushSubscriptionRegistration(
                'https://push.baander.app/' . $userId->toString(),
                'public-key',
                'auth-key',
                'aes128gcm',
            ));
        }
        $this->manager->clear();

        foreach ([$first, $second] as $userId) {
            $owned = $notifications->findByUserId($userId);
            self::assertCount(1, $owned);
            self::assertTrue($owned[0]->getUserId()->equals($userId));
            $preference = $preferences->findByUserAndCategoryAndChannel($userId, NotificationCategory::Security, NotificationChannel::Email);
            self::assertNotNull($preference);
            self::assertTrue($preference->getUserId()->equals($userId));
            self::assertFalse($preferences->isEnabled($userId, NotificationCategory::Security, NotificationChannel::Email));
            self::assertCount(1, $preferences->findByUserId($userId));
            $pushes = $subscriptions->findByUser($userId);
            self::assertCount(1, $pushes);
            self::assertTrue($pushes[0]->getUserId()->equals($userId));
        }

        // An existing preference is updated in place, not duplicated.
        $preferences->save(NotificationPreference::create($first, NotificationCategory::Security, NotificationChannel::Email, true));
        $this->manager->clear();
        self::assertCount(1, $preferences->findByUserId($first));
        self::assertTrue($preferences->isEnabled($first, NotificationCategory::Security, NotificationChannel::Email));

        $connection = $this->manager->getConnection();
        $connection->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $first->toString()]);

        foreach (array_keys(self::ENTITIES) as $table) {
            $sql = 'SELECT count(*) FROM ' . $table . ' WHERE user_id = :id';
            self::assertSame(0, (int) $connection->fetchOne($sql, ['id' => $first->toString()]), $table);
            self::assertSame(1, (int) $connection->fetchOne($sql, ['id' => $second->toString()]), $table);
        }
    }

    public function testForeignKeyRejectsANotificationForAnUnknownOwner(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        (new NotificationRepository($this->manager))->save($this->notification(Uuid::generate(), 'orphan'));
    }

    public function testReadsAndBulkMarkAllReadStayOwnerScoped(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();
        $repository = new NotificationRepository($this->manager);
        $connection = $this->manager->getConnection();

        // Interleave owners so every owner predicate is exercised against foreign rows.
        $ids = [];
        foreach ([[$first, 'a1'], [$second, 'b1'], [$first, 'a2'], [$second, 'b2'], [$first, 'a3']] as $offset => [$userId, $title]) {
            $notification = $this->notification($userId, $title);
            $repository->save($notification);
            $ids[$title] = $notification->getId();
            $connection->executeStatement(
                "UPDATE notifications SET created_at = TIMESTAMPTZ '2026-10-06 12:00:00+00' + make_interval(secs => :offset) WHERE id = :id",
                ['offset' => $offset * 10, 'id' => $notification->getId()->toString()],
            );
        }
        $repository->markAsRead($ids['a1']);
        $this->manager->clear();

        self::assertSame(2, $repository->countUnread($first));
        self::assertSame(2, $repository->countUnread($second));

        $titles = static fn (array $notifications): array => array_map(static fn (Notification $n): string => $n->getTitle(), $notifications);
        self::assertSame(['a3', 'a2'], $titles($repository->findByUserId($first, limit: 2)));
        self::assertSame(['a1'], $titles($repository->findByUserId($first, limit: 2, cursor: $ids['a2']->toString())));
        self::assertSame(['a2', 'a3'], $titles($repository->findByUserId($first, limit: 2, cursor: $ids['a1']->toString(), direction: 'asc')));
        self::assertSame(['a3', 'a2'], $titles($repository->findByUserId($first, unreadOnly: true)));
        self::assertSame(
            ['a3', 'a2'],
            $titles($repository->findByUserId($first, since: new \DateTimeImmutable('2026-10-06T14:00:15+02:00'))),
        );
        self::assertSame(['b2'], $titles($repository->findByUserId($second, since: new \DateTimeImmutable('2026-10-06T12:00:15Z'))));
        self::assertSame(['a2', 'a3'], $titles($repository->findAfterId($first, $ids['a1'])));

        $repository->markAllAsRead($first);
        $this->manager->clear();

        self::assertSame(0, $repository->countUnread($first));
        self::assertSame(2, $repository->countUnread($second));
        self::assertSame([], $repository->findByUserId($first, unreadOnly: true));
        self::assertSame(['b2', 'b1'], $titles($repository->findByUserId($second, unreadOnly: true)));
    }

    public function testCreatedAtMigrationPreservesExistingInstants(): void
    {
        $connection = $this->manager->getConnection();
        require_once dirname(__DIR__, 2) . '/migrations/Version20261006170000.php';
        $type = static fn (): string => (string) $connection->fetchOne(
            "SELECT format_type(atttypid, atttypmod) FROM pg_attribute WHERE attrelid = 'push_subscriptions'::regclass AND attname = 'created_at'",
        );
        $run = static function (string $direction) use ($connection): void {
            // A migration instance accumulates planned SQL, so each direction gets its own.
            $migration = new Version20261006170000($connection, new NullLogger());
            $migration->{$direction}(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        };

        self::assertSame('timestamp with time zone', $type());

        // Recreate the pre-migration column, then write rows through the production path.
        $run('down');
        self::assertSame('timestamp(0) without time zone', $type());
        $owner = $this->createUser();
        $subscriptions = new PushSubscriptionRepository($this->manager);
        $subscriptions->registerForUser($owner, new PushSubscriptionRegistration('https://push.baander.app/written', 'pk', 'ak', 'aes128gcm'));
        $written = (string) $connection->fetchOne(
            'SELECT created_at::text FROM push_subscriptions WHERE endpoint = :endpoint',
            ['endpoint' => 'https://push.baander.app/written'],
        );
        $subscriptions->registerForUser($owner, new PushSubscriptionRegistration('https://push.baander.app/boundary', 'pk', 'ak', 'aes128gcm'));
        // 02:30 does not exist in Copenhagen on this date; a session-zone conversion would shift it.
        $connection->executeStatement(
            "UPDATE push_subscriptions SET created_at = TIMESTAMP '2026-03-29 02:30:00' WHERE endpoint = 'https://push.baander.app/boundary'",
        );

        $connection->executeStatement("SET LOCAL TimeZone = 'Europe/Copenhagen'");
        $run('up');
        $connection->executeStatement("SET LOCAL TimeZone = 'UTC'");
        self::assertSame('timestamp with time zone', $type());

        $epoch = static fn (string $endpoint): int => (int) $connection->fetchOne(
            'SELECT extract(epoch FROM created_at)::bigint FROM push_subscriptions WHERE endpoint = :endpoint',
            ['endpoint' => $endpoint],
        );
        $writtenUtc = new \DateTimeImmutable($written, new \DateTimeZone('UTC'));
        self::assertSame($writtenUtc->getTimestamp(), $epoch('https://push.baander.app/written'));
        self::assertSame(
            (new \DateTimeImmutable('2026-03-29 02:30:00', new \DateTimeZone('UTC')))->getTimestamp(),
            $epoch('https://push.baander.app/boundary'),
        );

        $this->manager->clear();
        $hydrated = [];
        foreach ($subscriptions->findByUser($owner) as $subscription) {
            $hydrated[$subscription->getEndpoint()] = $subscription->getCreatedAt()->getTimestamp();
        }
        self::assertSame($writtenUtc->getTimestamp(), $hydrated['https://push.baander.app/written']);
    }

    private function notification(Uuid $userId, string $title): Notification
    {
        return Notification::create($userId, NotificationCategory::Security, 'test.event', $title, 'Body');
    }

    private function createUser(): Uuid
    {
        $id = Uuid::generate();
        $this->manager->persist(new UserEntity(
            new PublicId(),
            'Notification owner',
            $id->toString() . '@baander.app',
            'unused-test-password',
            '',
            $id,
        ));
        $this->manager->flush();

        return $id;
    }
}
