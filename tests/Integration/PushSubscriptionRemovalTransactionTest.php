<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Notification\Infrastructure\Doctrine\Entity\PushSubscriptionEntity;
use App\Notification\Infrastructure\Push\PushSubscriptionRepository;
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
}
