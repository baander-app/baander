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

    /**
     * Two grants of one membership race: the second waits on the first's uncommitted row and,
     * once that commits, inserts nothing and still succeeds.
     */
    public function testConcurrentGrantsOfOneMembershipBothSucceed(): void
    {
        $this->observer->executeStatement('DELETE FROM user_library_access');
        $this->observer->beginTransaction();
        $this->observer->executeStatement(
            'INSERT INTO user_library_access (user_id, library_id) VALUES (:userId, :libraryId)',
            ['userId' => $this->userId->toString(), 'libraryId' => $this->libraryId->toString()],
        );

        $application = 'library_access_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        self::assertIsResource($output);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/Library/library-access-grant.php', $this->schemaName, $application, $this->userId->toString(), $this->libraryId->toString()],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        self::assertIsResource($process);

        try {
            $this->awaitLockWait($application, $process, $output);
        } finally {
            $this->observer->commit();
        }

        $deadline = microtime(true) + 10;
        while (proc_get_status($process)['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                self::fail('The second grant did not finish after the first committed.');
            }
            usleep(1000);
        }
        rewind($output);
        $result = (string) stream_get_contents($output);
        fclose($output);

        self::assertSame(0, proc_close($process), $result);
        self::assertSame(['granted' => true], json_decode($result, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(1, $this->membershipCount());
    }

    /**
     * @param resource $process
     * @param resource $output
     */
    private function awaitLockWait(string $application, $process, $output): void
    {
        $deadline = microtime(true) + 5;
        while (true) {
            // The observer is inside its transaction; drop its cached statistics snapshot.
            $this->observer->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            $waiting = $this->observer->fetchOne(
                "SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'",
                [$application],
            );
            if ($waiting !== false) {
                return;
            }
            if (microtime(true) > $deadline || !proc_get_status($process)['running']) {
                rewind($output);
                self::fail('The second grant must wait on the uncommitted membership: ' . stream_get_contents($output));
            }
            usleep(1000);
        }
    }

    private function membershipCount(): int
    {
        return (int) $this->observer->fetchOne(
            'SELECT COUNT(*) FROM user_library_access WHERE user_id = :userId AND library_id = :libraryId',
            ['userId' => $this->userId->toString(), 'libraryId' => $this->libraryId->toString()],
        );
    }
}
