<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Notification\Infrastructure\Push\PushSubscriptionRepository;
use App\Notification\Application\DTO\PushSubscriptionRegistration;
use App\Notification\Application\DTO\PushSubscriptionRegistrationResult;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PushSubscriptionRemovalTransactionTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private EntityManager $manager;
    private string $schema;

    protected function setUp(): void
    {
        CustomTypesRegistrar::register();
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        self::assertNotFalse($url, 'Run with disposable PostgreSQL.');
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $dbal = new Configuration();
        $dbal->setMiddlewares([new BaanderDriverMiddleware()]);
        $this->writer = DriverManager::getConnection($params, $dbal);
        $this->observer = DriverManager::getConnection($params, $dbal);
        $this->schema = 'push_removal_tx_' . bin2hex(random_bytes(8));
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->writer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        $this->observer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['users', 'push_subscriptions'] as $table) {
            $this->writer->executeStatement('CREATE TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL)');
        }
        $this->writer->executeStatement('ALTER TABLE push_subscriptions ADD FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $orm = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src'], isDevMode: true);
        $orm->setNamingStrategy(new UnderscoreNamingStrategy());
        $orm->enableNativeLazyObjects(true);
        $this->manager = new EntityManager($this->writer, $orm);
    }

    protected function tearDown(): void
    {
        if (isset($this->writer)) {
            if ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
            if (isset($this->schema)) {
                $this->writer->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            }
            $this->writer->close();
        }
        if (isset($this->observer)) {
            $this->observer->close();
        }
        parent::tearDown();
    }

    public function testOuterRollbackRestoresDeletedRowAndSubsequentOrmReadUsesFreshIdentity(): void
    {
        $owner = new UserEntity(new PublicId(), 'Owner', 'push-transaction@baander.app', 'test-only', '');
        $target = new PushSubscriptionEntity($owner, 'https://push.baander.app/transaction', 'pk', 'ak', 'aes128gcm');
        $this->manager->persist($owner);
        $this->manager->persist($target);
        $this->manager->flush();
        $this->manager->clear();
        $managed = $this->manager->find(PushSubscriptionEntity::class, $target->getId());
        self::assertNotNull($managed);
        self::assertSame(1, $this->countFrom($this->observer));
        $this->writer->beginTransaction();
        (new PushSubscriptionRepository($this->manager))->removeForUser($owner->getId(), $target->getEndpoint());
        self::assertSame(0, $this->countFrom($this->writer));
        self::assertSame(1, $this->countFrom($this->observer));
        self::assertFalse($this->manager->contains($managed));
        self::assertTrue($this->writer->isTransactionActive());
        $this->writer->rollBack();
        self::assertSame(1, $this->countFrom($this->observer));
        $restored = $this->manager->find(PushSubscriptionEntity::class, $target->getId());
        self::assertNotNull($restored);
        self::assertNotSame($managed, $restored);
        self::assertSame($target->getEndpoint(), $restored->getEndpoint());
        self::assertTrue($this->manager->contains($restored));
    }

    private function countFrom(Connection $connection): int
    {
        return (int) $connection->fetchOne('SELECT COUNT(*) FROM push_subscriptions');
    }

    public function testCommittedRemovalDetachesLazyReferenceWithoutInitializingDeletedRow(): void
    {
        $owner = new UserEntity(new PublicId(), 'Owner', 'push-lazy@baander.app', 'test-only', '');
        $target = new PushSubscriptionEntity($owner, 'https://push.baander.app/lazy', 'pk', 'ak', 'aes128gcm');
        $this->manager->persist($owner);
        $this->manager->persist($target);
        $this->manager->flush();
        $this->manager->clear();
        $reference = $this->manager->getReference(PushSubscriptionEntity::class, $target->getId());
        self::assertNotNull($reference);
        self::assertTrue($this->manager->isUninitializedObject($reference));
        $this->writer->beginTransaction();
        (new PushSubscriptionRepository($this->manager))->removeForUser($owner->getId(), $target->getEndpoint());
        self::assertFalse($this->manager->contains($reference));
        self::assertTrue($this->manager->isUninitializedObject($reference));
        self::assertNull($this->manager->find(PushSubscriptionEntity::class, $target->getId()));
        self::assertSame(1, $this->countFrom($this->observer));
        $this->writer->commit();
        self::assertSame(0, $this->countFrom($this->observer));
    }

    /** @return iterable<string, array{bool}> */
    public static function managedStates(): iterable
    {
        yield 'hydrated' => [false];
        yield 'lazy' => [true];
    }

    #[DataProvider('managedStates')]
    public function testRotationPreservesIdentityPendingWorkAndRollback(bool $lazy): void
    {
        $owner = new UserEntity(new PublicId(), 'Stored name', 'push-rotation@baander.app', 'test-only', '');
        $target = new PushSubscriptionEntity($owner, 'https://push.baander.app/rotation', 'old-public', 'old-auth', 'aes128gcm', 'old-agent');
        $this->manager->persist($owner);
        $this->manager->persist($target);
        $this->manager->flush();
        $before = $this->observer->fetchAssociative('SELECT * FROM push_subscriptions');
        self::assertIsArray($before);
        $this->manager->clear();
        $managed = $lazy
            ? $this->manager->getReference(PushSubscriptionEntity::class, $target->getId())
            : $this->manager->find(PushSubscriptionEntity::class, $target->getId());
        self::assertNotNull($managed);
        $managedOwner = $this->manager->find(UserEntity::class, $owner->getId());
        self::assertNotNull($managedOwner);
        $managedOwner->setName('Pending name');
        $repository = new PushSubscriptionRepository($this->manager);
        $this->writer->beginTransaction();
        self::assertSame(PushSubscriptionRegistrationResult::Updated, $repository->registerForUser($owner->getId(),
            new PushSubscriptionRegistration($target->getEndpoint(), 'new-public', 'new-auth', 'aesgcm', 'new-agent')));
        self::assertFalse($this->manager->contains($managed));
        if ($lazy) {
            self::assertTrue($this->manager->isUninitializedObject($managed));
        }
        $after = $this->writer->fetchAssociative('SELECT * FROM push_subscriptions');
        self::assertIsArray($after);
        self::assertSame($before['id'], $after['id']);
        self::assertSame($before['created_at'], $after['created_at']);
        self::assertSame('new-public', $after['public_key']);
        self::assertSame('new-auth', $after['auth_key']);
        self::assertSame('aesgcm', $after['content_encoding']);
        self::assertSame('new-agent', $after['user_agent']);
        self::assertSame($before, $this->observer->fetchAssociative('SELECT * FROM push_subscriptions'));
        self::assertSame('Stored name', $this->writer->fetchOne('SELECT name FROM users'));
        self::assertTrue($this->manager->contains($managedOwner));
        self::assertSame('Pending name', $managedOwner->getName());
        self::assertTrue($this->writer->isTransactionActive());
        $this->writer->rollBack();
        $restored = $this->manager->find(PushSubscriptionEntity::class, $target->getId());
        self::assertNotNull($restored);
        self::assertSame('old-public', $restored->getPublicKey());
        self::assertNotSame($managed, $restored);
        $this->manager->flush();
        self::assertSame('Pending name', $this->observer->fetchOne('SELECT name FROM users'));
        self::assertSame($before, $this->observer->fetchAssociative('SELECT * FROM push_subscriptions'));
    }

    public function testRemoveAllPreservesForeignLazyIdentityAndCallerTransaction(): void
    {
        $owner = new UserEntity(new PublicId(), 'Owner', 'push-all-owner@baander.app', 'test-only', '');
        $other = new UserEntity(new PublicId(), 'Other', 'push-all-other@baander.app', 'test-only', '');
        $target = new PushSubscriptionEntity($owner, 'https://push.baander.app/all', 'pk', 'ak', 'aes128gcm');
        $foreign = new PushSubscriptionEntity($other, 'https://push.baander.app/foreign', 'pk', 'ak', 'aes128gcm');
        foreach ([$owner, $other, $target, $foreign] as $entity) {
            $this->manager->persist($entity);
        }
        $this->manager->flush();
        $this->manager->clear();
        $managedTarget = $this->manager->getReference(PushSubscriptionEntity::class, $target->getId());
        $managedForeign = $this->manager->getReference(PushSubscriptionEntity::class, $foreign->getId());
        self::assertNotNull($managedTarget);
        self::assertNotNull($managedForeign);
        $managedOwner = $this->manager->find(UserEntity::class, $owner->getId());
        self::assertNotNull($managedOwner);
        $managedOwner->setName('Pending');
        $this->writer->beginTransaction();
        $repository = new PushSubscriptionRepository($this->manager);
        $repository->removeAllForUser($owner->getId());
        $repository->removeAllForUser($owner->getId());
        self::assertFalse($this->manager->contains($managedTarget));
        self::assertTrue($this->manager->isUninitializedObject($managedTarget));
        self::assertTrue($this->manager->contains($managedForeign));
        self::assertTrue($this->manager->isUninitializedObject($managedForeign));
        self::assertSame(1, $this->countFrom($this->writer));
        self::assertSame(2, $this->countFrom($this->observer));
        self::assertSame('Owner', $this->writer->fetchOne('SELECT name FROM users WHERE id = :id', ['id' => $owner->getId()->toString()]));
        self::assertTrue($this->writer->isTransactionActive());
        $this->writer->rollBack();
        self::assertSame(2, $this->countFrom($this->observer));
        self::assertNotNull($this->manager->find(PushSubscriptionEntity::class, $target->getId()));
    }

    #[DataProvider('managedStates')]
    public function testConcurrentRegistrationSerializesSameOrDifferentOwners(bool $differentOwner): void
    {
        $owner = new UserEntity(new PublicId(), 'Owner', 'push-race-owner@baander.app', 'test-only', '');
        $other = new UserEntity(new PublicId(), 'Other', 'push-race-other@baander.app', 'test-only', '');
        $this->manager->persist($owner);
        $this->manager->persist($other);
        $this->manager->flush();
        $this->writer->beginTransaction();
        $endpoint = 'https://push.baander.app/race';
        self::assertSame(PushSubscriptionRegistrationResult::Created, (new PushSubscriptionRepository($this->manager))
            ->registerForUser($owner->getId(), new PushSubscriptionRegistration($endpoint, 'first', 'first-auth', 'aes128gcm')));
        $firstId = $this->writer->fetchOne('SELECT id FROM push_subscriptions');
        $applicationName = 'push_race_' . bin2hex(random_bytes(8));
        $output = tmpfile();
        self::assertIsResource($output);
        $code = <<<'PHP'
require $argv[1];
\App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar::register();
$params = (new \Doctrine\DBAL\Tools\DsnParser(['postgresql' => 'pdo_pgsql']))->parse(getenv('OUTBOX_TEST_DATABASE_URL'));
$connection = \Doctrine\DBAL\DriverManager::getConnection($params);
$connection->executeStatement('SET search_path TO ' . $argv[2] . ', public');
$connection->executeQuery("SELECT set_config('application_name', :name, false)", ['name' => $argv[3]])->free();
$connection->executeStatement("SET lock_timeout TO '5s'");
$connection->executeStatement("SET statement_timeout TO '8s'");
$orm = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfig([dirname($argv[1], 2) . '/src'], true);
$orm->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy());
$orm->enableNativeLazyObjects(true);
$manager = new \Doctrine\ORM\EntityManager($connection, $orm);
$result = (new \App\Notification\Infrastructure\Push\PushSubscriptionRepository($manager))->registerForUser(
    \App\Shared\Domain\Model\Uuid::fromString($argv[4]),
    new \App\Notification\Application\DTO\PushSubscriptionRegistration('https://push.baander.app/race', 'second', 'second-auth', 'aesgcm')
);
echo $result->name;
PHP;
        $process = proc_open([PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 2) . '/vendor/autoload.php', $this->schema,
            $applicationName, ($differentOwner ? $other : $owner)->getId()->toString()],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes);
        self::assertIsResource($process);
        try {
            $deadline = microtime(true) + 3;
            do {
                $waiting = (int) $this->observer->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name = :name AND wait_event_type = 'Lock'", ['name' => $applicationName]);
                if ($waiting === 1) {
                    break;
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
            self::assertSame(1, $waiting, 'Contender must reach the actual unique-index lock before the first transaction commits.');
            self::assertSame(0, $this->countFrom($this->observer));
            $this->writer->commit();
            $status = proc_close($process);
            rewind($output);
            $result = stream_get_contents($output);
            self::assertSame(0, $status, $result);
            self::assertSame($differentOwner ? 'Conflict' : 'Updated', $result);
            self::assertSame(1, $this->countFrom($this->observer));
            $row = $this->observer->fetchAssociative('SELECT * FROM push_subscriptions');
            self::assertIsArray($row);
            self::assertSame($owner->getId()->toString(), $row['user_id']);
            self::assertSame($firstId, $row['id']);
            self::assertSame($differentOwner ? 'first' : 'second', $row['public_key']);
            self::assertTrue($this->manager->isOpen());
        } finally {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
            fclose($output);
        }
    }
}
