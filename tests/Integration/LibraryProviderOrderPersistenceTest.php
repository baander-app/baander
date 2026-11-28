<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Domain\Model\LibraryProviderOrder;
use App\Catalog\Infrastructure\Doctrine\Repository\LibraryProviderOrderRepository;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Catalog\ArtistImportIdentityRace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use DoctrineMigrations\Version20261128121000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/migrations/Version20261128121000.php';

final class LibraryProviderOrderPersistenceTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private EntityManager $manager;
    private LibraryProviderOrderRepository $orders;
    private string $schema;
    private Uuid $libraryId;

    protected function setUp(): void
    {
        if (!getenv('OUTBOX_TEST_DATABASE_URL')) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to migrated disposable PostgreSQL.');
        }
        $this->schema = 'provider_order_' . bin2hex(random_bytes(8));
        $this->writer = ArtistImportIdentityRace::connect('public');
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        $this->writer->executeStatement('SET search_path TO ' . $this->schema . ', public');
        foreach (['libraries', 'query_change_inbox'] as $table) {
            ArtistImportIdentityRace::copyTableWithoutPgroonga($this->writer, $table);
        }
        $migration = new Version20261128121000($this->writer, new NullLogger());
        $migration->up(new Schema());
        $this->writer->transactional(static function (Connection $connection) use ($migration): void {
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        });
        $this->manager = ArtistImportIdentityRace::entityManager($this->writer);
        $library = new LibraryEntity('Movies', 'provider-order', '/media/movies', 'movie', 'local');
        $this->manager->persist($library);
        $this->manager->flush();
        $this->libraryId = $library->getId();
        $this->orders = new LibraryProviderOrderRepository($this->writer);
        $this->observer = ArtistImportIdentityRace::connect($this->schema);
    }

    protected function tearDown(): void
    {
        if (isset($this->writer)) {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
            if (isset($this->manager)) {
                $this->manager->close();
            }
            if (isset($this->observer)) {
                $this->observer->close();
            }
            $this->writer->executeStatement('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            $this->writer->close();
        }
    }

    public function testReadingAnUnconfiguredLibraryDoesNotStoreAnOrder(): void
    {
        self::assertNull($this->orders->findByLibrary($this->libraryId));
        self::assertSame(0, (int) $this->writer->fetchOne('SELECT count(*) FROM library_provider_orders'));
    }

    public function testSavedOrderRoundTripsAndAnUpdateReplacesIt(): void
    {
        $this->orders->save($this->order(['tmdb', 'local']));
        self::assertSame(['tmdb', 'local'], $this->orders->findByLibrary($this->libraryId)?->getProviderIds());

        $this->orders->save($this->order(['local', 'tmdb']));

        self::assertSame(['local', 'tmdb'], $this->orders->findByLibrary($this->libraryId)?->getProviderIds());
        self::assertSame(1, (int) $this->writer->fetchOne('SELECT count(*) FROM library_provider_orders'));
    }

    public function testRollbackRestoresTheOrderAndItsCapture(): void
    {
        $this->orders->save($this->order(['tmdb', 'local']));
        $captured = $this->writer->fetchAllAssociative('SELECT family, scope_kind, scope_id FROM query_change_inbox ORDER BY id');
        self::assertSame([['family' => 'admin-metadata', 'scope_kind' => 'admin', 'scope_id' => '*']], $captured);
        $this->writer->beginTransaction();
        $this->orders->save($this->order(['local', 'tmdb']));
        self::assertSame(['tmdb', 'local'], (new LibraryProviderOrderRepository($this->observer))->findByLibrary($this->libraryId)?->getProviderIds());

        $this->writer->rollBack();

        self::assertSame(['tmdb', 'local'], $this->orders->findByLibrary($this->libraryId)?->getProviderIds());
        self::assertSame($captured, $this->writer->fetchAllAssociative('SELECT family, scope_kind, scope_id FROM query_change_inbox ORDER BY id'));
    }

    public function testDeletingTheLibraryDeletesItsStoredOrder(): void
    {
        $this->orders->save($this->order(['tmdb', 'local']));

        $this->writer->executeStatement('DELETE FROM libraries WHERE id = ?', [$this->libraryId->toString()]);

        self::assertNull($this->orders->findByLibrary($this->libraryId));
    }

    public function testAnOrderCannotReferenceAMissingLibrary(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->orders->save(LibraryProviderOrder::fromProviders(Uuid::v7(), ['tmdb', 'local'], ['tmdb', 'local']));
    }

    public function testConcurrentFirstWritesStoreOneCompleteOrder(): void
    {
        $this->writer->beginTransaction();
        $this->orders->save($this->order(['tmdb', 'local']));
        $child = $this->startContender();
        try {
            $this->awaitLockWait($child);
            self::assertNull((new LibraryProviderOrderRepository($this->observer))->findByLibrary($this->libraryId));
            $this->writer->commit();
        } finally {
            while ($this->writer->isTransactionActive()) {
                $this->writer->rollBack();
            }
            $result = ArtistImportIdentityRace::finish($child, $this->observer);
        }

        self::assertSame(['saved' => true], $result);
        self::assertSame(['local', 'tmdb'], $this->orders->findByLibrary($this->libraryId)?->getProviderIds());
        self::assertSame(1, (int) $this->writer->fetchOne('SELECT count(*) FROM library_provider_orders'));
    }

    /** @param list<string> $providers */
    private function order(array $providers): LibraryProviderOrder
    {
        return LibraryProviderOrder::fromProviders($this->libraryId, $providers, ['tmdb', 'local']);
    }

    /** @return array{process: resource, output: resource, application: string} */
    private function startContender(): array
    {
        $application = 'provider_order_' . bin2hex(random_bytes(6));
        $output = tmpfile();
        if ($output === false) {
            throw new RuntimeException('Cannot create provider order contender output.');
        }
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/Catalog/library-provider-order-save.php', $this->schema, $application, $this->libraryId->toString()],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
        );
        if (!is_resource($process)) {
            fclose($output);
            throw new RuntimeException('Cannot start the provider order contender.');
        }

        return ['process' => $process, 'output' => $output, 'application' => $application];
    }

    /** @param array{process: resource, output: resource, application: string} $child */
    private function awaitLockWait(array $child): void
    {
        $deadline = microtime(true) + 10;
        do {
            $this->observer->executeQuery('SELECT pg_stat_clear_snapshot()')->free();
            if ($this->observer->fetchOne(
                "SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock' AND wait_event = 'transactionid'",
                [$child['application']],
            ) !== false) {
                return;
            }
            if (!proc_get_status($child['process'])['running']) {
                break;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        rewind($child['output']);
        throw new RuntimeException('Provider order contender did not wait for the first write: ' . stream_get_contents($child['output']));
    }
}
