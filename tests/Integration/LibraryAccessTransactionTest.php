<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Repository\LibraryAccessRepository;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LibraryAccessTransactionTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private EntityManagerInterface $manager;
    private LibraryAccessRepository $repository;
    private string $schemaName;
    private Uuid $userId;
    private Uuid $libraryId;

    protected function setUp(): void
    {
        CustomTypesRegistrar::register();
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        self::assertNotFalse($url, 'Run with the disposable PostgreSQL functional runner.');
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $dbal = new Configuration();
        $dbal->setMiddlewares([new BaanderDriverMiddleware()]);
        $this->writer = DriverManager::getConnection($params, $dbal);
        $this->observer = DriverManager::getConnection($params, $dbal);
        $this->schemaName = 'library_access_tx_' . bin2hex(random_bytes(8));
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schemaName);
        $this->writer->executeStatement('SET search_path TO ' . $this->schemaName . ', public');
        $this->observer->executeStatement('SET search_path TO ' . $this->schemaName . ', public');
        foreach (['users', 'libraries', 'user_library_access'] as $table) {
            $this->writer->executeStatement('CREATE TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL)');
        }
        $this->writer->executeStatement('ALTER TABLE user_library_access ADD FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->writer->executeStatement('ALTER TABLE user_library_access ADD FOREIGN KEY (library_id) REFERENCES libraries (id) ON DELETE CASCADE');
        $orm = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src'], isDevMode: true);
        $orm->setNamingStrategy(new UnderscoreNamingStrategy());
        $orm->enableNativeLazyObjects(true);
        $this->manager = new EntityManager($this->writer, $orm);
        $this->repository = new LibraryAccessRepository($this->manager);
        $user = new UserEntity(new PublicId(), 'Transaction User', 'library-transaction@baander.app', 'test-only', '');
        $library = new LibraryEntity('Transaction Library', 'transaction-library', '/media/transaction', 'music', 'local');
        $this->manager->persist($user);
        $this->manager->persist($library);
        $this->manager->flush();
        $this->userId = $user->getId();
        $this->libraryId = $library->getId();
        $this->repository->grant($this->userId, $this->libraryId);
        $this->manager->clear();
    }

    protected function tearDown(): void
    {
        if (isset($this->observer)) {
            $this->observer->executeStatement('DROP SCHEMA ' . $this->schemaName . ' CASCADE');
            $this->observer->close();
        }
        if (isset($this->writer)) {
            $this->writer->close();
        }
        parent::tearDown();
    }

    public function testIndependentObserverSeesCommittedRevocationAndRegrant(): void
    {
        self::assertSame(1, $this->membershipCount());
        $this->repository->revoke($this->userId, $this->libraryId);
        self::assertSame(0, $this->membershipCount());
        $this->repository->grant($this->userId, $this->libraryId);
        self::assertSame(1, $this->membershipCount());
        self::assertTrue($this->repository->hasAccess($this->userId, $this->libraryId));
    }

    public function testFlushFailureRollsBackRevocationForIndependentObserver(): void
    {
        $listener = new class($this->observer) {
            public ?int $membershipCountDuringFlush = null;

            public function __construct(private readonly Connection $observer)
            {
            }

            public function onFlush(): never
            {
                $this->membershipCountDuringFlush = (int) $this->observer->fetchOne('SELECT COUNT(*) FROM user_library_access');
                throw new RuntimeException('Reject pending changes during flush.');
            }
        };
        $this->manager->getEventManager()->addEventListener(['onFlush'], $listener);
        try {
            $this->repository->revoke($this->userId, $this->libraryId);
            self::fail('The flush failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('Reject pending changes during flush.', $exception->getMessage());
        }
        self::assertSame(1, $listener->membershipCountDuringFlush);
        self::assertSame(1, $this->membershipCount());
        self::assertFalse($this->manager->isOpen());
        self::assertFalse($this->writer->isTransactionActive());
    }

    private function membershipCount(): int
    {
        return (int) $this->observer->fetchOne(
            'SELECT COUNT(*) FROM user_library_access WHERE user_id = :userId AND library_id = :libraryId',
            ['userId' => $this->userId->toString(), 'libraryId' => $this->libraryId->toString()],
        );
    }
}
