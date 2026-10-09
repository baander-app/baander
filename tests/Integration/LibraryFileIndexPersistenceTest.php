<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Infrastructure\Doctrine\Entity\LibraryFileIndexEntity;
use App\Library\Infrastructure\Doctrine\Repository\LibraryFileIndexRepository;
use App\Library\Infrastructure\Doctrine\Repository\LibraryRepository;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Version\Version;
use Doctrine\ORM\Tools\SchemaTool;
use DoctrineMigrations\Version20261006190000;
use DoctrineMigrations\Version20261006201000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;

final class LibraryFileIndexPersistenceTest extends TestCase
{
    use OwnershipPersistenceHarness;

    private const FOREIGN_KEY = 'fk_library_file_index_library_id';

    public function testDeclaredForeignKeyMatchesTheCatalog(): void
    {
        $expected = (new SchemaTool($this->manager))
            ->getSchemaFromMetadata($this->manager->getMetadataFactory()->getAllMetadata())
            ->getTable('library_file_index')
            ->getForeignKey(self::FOREIGN_KEY);
        $actual = $this->manager->getConnection()->createSchemaManager()
            ->introspectTable('library_file_index')
            ->getForeignKey(self::FOREIGN_KEY);

        foreach ([$expected, $actual] as $foreignKey) {
            self::assertSame(['library_id'], $foreignKey->getLocalColumns());
            self::assertSame('libraries', $foreignKey->getForeignTableName());
            self::assertSame(['id'], $foreignKey->getForeignColumns());
            self::assertSame('CASCADE', $foreignKey->onDelete());
        }
    }

    public function testSchemaComparisonIsCleanForTheFileIndex(): void
    {
        $this->assertSchemaComparisonIsClean(['library_file_index']);
    }

    public function testDeletingALibraryRemovesOnlyItsIndexRows(): void
    {
        $libraries = new LibraryRepository($this->manager, new NativeClock());
        $deleted = $this->createLibrary();
        $kept = $this->createLibrary();
        $this->index($deleted, '/media/u15/a.flac', '/media/u15/b.flac');
        $this->index($kept, '/media/u15/a.flac');
        $this->manager->clear();

        $libraries->delete($deleted);

        self::assertSame(0, $this->countIndexRows($deleted->getId()));
        self::assertSame(1, $this->countIndexRows($kept->getId()));
    }

    /**
     * A scan writes the index without a flush: a scan that relied on a later flush lost its rows,
     * and its next upsert of the same path inserted the path a second time.
     */
    public function testEachIndexWriteIsStoredWithoutAFlushAndAnUpsertUpdatesTheKnownPath(): void
    {
        $library = $this->createLibrary();
        $repository = new LibraryFileIndexRepository($this->manager);

        $repository->upsert($library->getId(), '/media/u15/a.flac', 'first', 1, 'flac', 10);
        self::assertSame(['/media/u15/a.flac' => 'first'], $repository->findIndexPathMapByLibrary($library->getId()));

        $repository->upsert($library->getId(), '/media/u15/a.flac', 'second', 2, 'flac', 20);
        self::assertSame(1, $this->countIndexRows($library->getId()));
        self::assertSame(
            ['hash' => 'second', 'size' => 2, 'modified_at' => 20],
            $this->manager->getConnection()->fetchAssociative(
                'SELECT hash, size, modified_at FROM library_file_index WHERE library_id = ?',
                [$library->getId()->toString()],
            ),
        );

        $repository->removeByPath($library->getId(), '/media/u15/a.flac');
        self::assertSame(0, $this->countIndexRows($library->getId()));
    }

    /** Video files pass 2 GiB, and file times pass January 2038; both fit and read back unchanged. */
    public function testIndexesAFileLargerThan2GibModifiedAfter2038(): void
    {
        $library = $this->createLibrary();
        $repository = new LibraryFileIndexRepository($this->manager);
        $size = 5 * 1024 ** 3;
        $modifiedAt = 4_102_444_800; // 2100-01-01T00:00:00Z

        $repository->upsert($library->getId(), '/media/u15/remux.mkv', 'hash', $size, 'mkv', $modifiedAt);

        self::assertSame(
            ['size' => $size, 'modified_at' => $modifiedAt],
            $this->manager->getConnection()->fetchAssociative(
                'SELECT size, modified_at FROM library_file_index WHERE library_id = ?',
                [$library->getId()->toString()],
            ),
        );
        $this->manager->clear();
        $entity = $this->manager->getRepository(LibraryFileIndexEntity::class)->findOneBy(['libraryId' => $library->getId()]);
        self::assertInstanceOf(LibraryFileIndexEntity::class, $entity);
        self::assertSame($size, $entity->getSize());
        self::assertSame($modifiedAt, $entity->getModifiedAt());
        self::assertSame(
            ['modified_at' => 'bigint', 'size' => 'bigint'],
            $this->manager->getConnection()->fetchAllKeyValue(
                "SELECT column_name, data_type FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = 'library_file_index' AND column_name IN ('size', 'modified_at')
                 ORDER BY column_name",
            ),
        );
    }

    public function testIndexRowForAnUnknownLibraryIsRejected(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->index(null, '/media/u15/orphan.flac');
    }

    public function testMigrationRemovesOrphanedRowsBeforeAddingTheForeignKeyAndIsRecorded(): void
    {
        $connection = $this->manager->getConnection();
        $migrations = $this->kernel->getContainer()->get('test.service_container')->get('doctrine.migrations.dependency_factory');
        self::assertInstanceOf(DependencyFactory::class, $migrations);
        // The runner has already migrated twice; this migration is recorded and nothing remains to run.
        self::assertTrue($migrations->getMetadataStorage()->getExecutedMigrations()->hasMigration(
            new Version(Version20261006190000::class),
        ));
        self::assertCount(0, $migrations->getMigrationStatusCalculator()->getNewMigrations());

        require_once dirname(__DIR__, 2) . '/migrations/Version20261006190000.php';
        $run = static function (string $direction) use ($connection): void {
            // A migration instance accumulates planned SQL, so each direction gets its own.
            $migration = new Version20261006190000($connection, new NullLogger());
            $migration->{$direction}(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        };
        $constraint = static fn (): string|false => $connection->fetchOne(
            'SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = \'library_file_index\'::regclass AND conname = :name',
            ['name' => self::FOREIGN_KEY],
        );

        // Recreate the pre-migration table state: no constraint, orphans left by earlier library deletions.
        $run('down');
        self::assertFalse($constraint());
        $library = $this->createLibrary();
        $this->index($library, '/media/u15/kept.flac');
        $orphan = Uuid::generate();
        foreach (['/media/u15/orphan-1.flac', '/media/u15/orphan-2.flac'] as $path) {
            $connection->insert('library_file_index', [
                'id' => Uuid::generate()->toString(),
                'library_id' => $orphan->toString(),
                'path' => $path,
                'hash' => 'hash',
                'size' => 1,
                'extension' => 'flac',
                'modified_at' => 0,
                'discovered_at' => '2026-10-06 12:00:00+00',
            ]);
        }
        self::assertSame(2, $this->countIndexRows($orphan));

        $run('up');

        self::assertSame(0, $this->countIndexRows($orphan));
        self::assertSame(1, $this->countIndexRows($library->getId()));
        self::assertSame('FOREIGN KEY (library_id) REFERENCES libraries(id) ON DELETE CASCADE', $constraint());
    }

    public function testTheUniquePathIndexAloneCoversLibraryLookups(): void
    {
        $connection = $this->manager->getConnection();
        $indexes = static fn (): array => $connection->fetchAllKeyValue(
            'SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = \'library_file_index\' ORDER BY indexname',
        );

        self::assertSame([
            'library_file_index_pkey' => 'CREATE UNIQUE INDEX library_file_index_pkey ON public.library_file_index USING btree (id)',
            'uniq_library_file_index_library_id_path' => 'CREATE UNIQUE INDEX uniq_library_file_index_library_id_path ON public.library_file_index USING btree (library_id, path)',
        ], $indexes());

        require_once dirname(__DIR__, 2) . '/migrations/Version20261006201000.php';
        foreach (['down', 'up'] as $direction) {
            $migration = new Version20261006201000($connection, new NullLogger());
            $migration->{$direction}(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
            self::assertSame($direction === 'down', array_key_exists('idx_library_file_index_library_id', $indexes()), $direction);
            self::assertArrayHasKey('uniq_library_file_index_library_id_path', $indexes(), $direction);
        }
    }

    private function createLibrary(): Library
    {
        $suffix = bin2hex(random_bytes(6));
        $library = Library::create(
            name: 'File index ' . $suffix,
            slug: new LibrarySlug('file-index-' . $suffix),
            path: new LibraryPath('/media/u15/' . $suffix),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );
        (new LibraryRepository($this->manager, new NativeClock()))->save($library);

        return $library;
    }

    /** A null library writes rows for a library ID that does not exist. */
    private function index(?Library $library, string ...$paths): void
    {
        $libraryId = $library?->getId() ?? Uuid::generate();
        $repository = new LibraryFileIndexRepository($this->manager);
        foreach ($paths as $path) {
            $repository->upsert($libraryId, $path, 'hash', 1, 'flac', 0);
        }
    }

    private function countIndexRows(Uuid $libraryId): int
    {
        return (int) $this->manager->getConnection()->fetchOne(
            'SELECT count(*) FROM library_file_index WHERE library_id = :library',
            ['library' => $libraryId->toString()],
        );
    }
}
