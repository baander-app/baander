<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Kernel;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity;
use App\Library\Infrastructure\Doctrine\Repository\LibraryAccessRepository;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DoctrineMigrations\Version20261006181000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Production mapping, migrated constraints and repository, on disposable PostgreSQL only. */
final class LibraryAccessOwnershipPersistenceTest extends TestCase
{
    private Kernel $kernel;
    private EntityManagerInterface $manager;
    private LibraryAccessRepository $repository;

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
        $this->repository = new LibraryAccessRepository($manager);
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

    public function testUserIsAScalarIdentifierBesideTheLibraryAssociation(): void
    {
        $metadata = $this->manager->getClassMetadata(UserLibraryAccessEntity::class);

        self::assertSame(['userId', 'library'], $metadata->getIdentifierFieldNames());
        self::assertFalse($metadata->hasAssociation('user'));
        self::assertSame('user_id', $metadata->getColumnName('userId'));
        self::assertSame('uuid', $metadata->getTypeOfField('userId'));
        self::assertTrue($metadata->isAssociationWithSingleJoinColumn('library'));

        $mapped = (new SchemaTool($this->manager))->getSchemaFromMetadata([$metadata]);
        $catalog = $this->manager->getConnection()->createSchemaManager()->introspectTable('user_library_access');
        self::assertSame(['user_id', 'library_id'], $mapped->getTable('user_library_access')->getPrimaryKey()?->getColumns());
        self::assertSame(['user_id', 'library_id'], $catalog->getPrimaryKey()?->getColumns());
    }

    public function testDeclaredUserForeignKeyMatchesTheCatalog(): void
    {
        $tool = new SchemaTool($this->manager);
        $schema = $tool->getSchemaFromMetadata($this->manager->getMetadataFactory()->getAllMetadata());
        $catalog = $this->manager->getConnection()->createSchemaManager()->introspectTable('user_library_access');

        $expected = $schema->getTable('user_library_access')->getForeignKey('fk_user_library_access_user');
        self::assertSame(['user_id'], $expected->getLocalColumns());
        self::assertSame('users', $expected->getForeignTableName());
        self::assertSame(['id'], $expected->getForeignColumns());
        self::assertSame('CASCADE', $expected->onDelete());

        $actual = $catalog->getForeignKey('fk_user_library_access_user');
        self::assertSame($actual->getLocalColumns(), $expected->getLocalColumns());
        self::assertSame($actual->getForeignTableName(), $expected->getForeignTableName());
        self::assertSame($actual->getForeignColumns(), $expected->getForeignColumns());
        self::assertSame($actual->onDelete(), $expected->onDelete());
    }

    public function testSchemaComparisonIsCleanForLibraryAccess(): void
    {
        $tool = new SchemaTool($this->manager);
        $metadata = $this->manager->getMetadataFactory()->getAllMetadata();
        $catalog = $this->manager->getConnection()->createSchemaManager();
        $mapped = $tool->getSchemaFromMetadata($metadata);

        // PostgreSQL's DROP INDEX names only the index, so match every asset on the table,
        // including DBAL's implicit foreign-key index names on either side of the comparison.
        $names = ['user_library_access'];
        foreach ([$catalog->introspectTable('user_library_access'), $mapped->getTable('user_library_access')] as $side) {
            foreach ($side->getIndexes() as $index) {
                $names[] = $index->getName();
            }
            foreach ($side->getForeignKeys() as $foreignKey) {
                $names[] = $foreignKey->getName();
            }
        }
        $pattern = '/\b(' . implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), array_unique($names))) . ')\b/i';

        $statements = array_values(array_filter(
            $tool->getUpdateSchemaSql($metadata),
            static fn (string $sql): bool => preg_match($pattern, $sql) === 1,
        ));

        self::assertSame([], $statements);
    }

    public function testRegrantAfterRevokingAHydratedMembershipLeavesOneRow(): void
    {
        $userId = $this->createUser();
        $libraryId = $this->createLibrary();
        $this->repository->grant($userId, $libraryId);
        $this->manager->clear();

        // hasAccess hydrates the membership into this persistence context before revoke.
        self::assertTrue($this->repository->hasAccess($userId, $libraryId));
        $this->repository->revoke($userId, $libraryId);
        $this->repository->grant($userId, $libraryId);

        self::assertTrue($this->repository->hasAccess($userId, $libraryId));
        self::assertSame(1, $this->rowCount('user_id = :id', $userId));
    }

    public function testDeletingAUserOrALibraryCascadesItsAccessRows(): void
    {
        $users = [$this->createUser(), $this->createUser()];
        $libraries = [$this->createLibrary(), $this->createLibrary()];
        foreach ($users as $userId) {
            foreach ($libraries as $libraryId) {
                $this->repository->grant($userId, $libraryId);
            }
        }
        $this->manager->clear();
        $connection = $this->manager->getConnection();

        $connection->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $users[0]->toString()]);
        self::assertSame(0, $this->rowCount('user_id = :id', $users[0]));
        self::assertSame(2, $this->rowCount('user_id = :id', $users[1]));

        $connection->executeStatement('DELETE FROM libraries WHERE id = :id', ['id' => $libraries[0]->toString()]);
        self::assertSame(0, $this->rowCount('library_id = :id', $libraries[0]));
        self::assertSame([$libraries[1]->toString()], $this->repository->getUserLibraryIds($users[1]));
    }

    public function testGrantedAtMigrationPreservesExistingInstants(): void
    {
        $connection = $this->manager->getConnection();
        require_once dirname(__DIR__, 2) . '/migrations/Version20261006181000.php';
        $column = static fn (): array => $connection->fetchAssociative(
            "SELECT format_type(a.atttypid, a.atttypmod) AS type, pg_get_expr(d.adbin, d.adrelid) AS default
             FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
             WHERE a.attrelid = 'user_library_access'::regclass AND a.attname = 'granted_at'",
        ) ?: [];
        $run = static function (string $direction) use ($connection): void {
            // A migration instance accumulates planned SQL, so each direction gets its own.
            $migration = new Version20261006181000($connection, new NullLogger());
            $migration->{$direction}(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        };

        self::assertSame(['type' => 'timestamp with time zone', 'default' => 'now()'], $column());

        // Recreate the pre-migration column, then write rows the way existing ones were written.
        $run('down');
        self::assertSame(['type' => 'timestamp(0) without time zone', 'default' => 'now()'], $column());
        $connection->executeStatement("SET LOCAL TimeZone = 'UTC'");
        $owner = $this->createUser();
        $written = $this->createLibrary();
        $this->repository->grant($owner, $written);
        $writtenText = (string) $connection->fetchOne(
            'SELECT granted_at::text FROM user_library_access WHERE library_id = :id',
            ['id' => $written->toString()],
        );
        $defaulted = $this->createLibrary();
        $connection->executeStatement(
            'INSERT INTO user_library_access (user_id, library_id) VALUES (:user, :library)',
            ['user' => $owner->toString(), 'library' => $defaulted->toString()],
        );
        $transactionStart = (int) $connection->fetchOne('SELECT round(extract(epoch FROM now()))::bigint');
        // In Copenhagen, 02:30 does not exist on 2026-03-29 and occurs twice on 2026-10-25;
        // a conversion through the session zone would shift or guess these instants.
        $boundaries = ['2026-03-29 02:30:00' => $this->createLibrary(), '2026-10-25 02:30:00' => $this->createLibrary()];
        foreach ($boundaries as $wallClock => $libraryId) {
            $connection->executeStatement(
                'INSERT INTO user_library_access (user_id, library_id, granted_at) VALUES (:user, :library, CAST(:at AS timestamp))',
                ['user' => $owner->toString(), 'library' => $libraryId->toString(), 'at' => $wallClock],
            );
        }

        $connection->executeStatement("SET LOCAL TimeZone = 'Europe/Copenhagen'");
        $run('up');
        $connection->executeStatement("SET LOCAL TimeZone = 'UTC'");
        self::assertSame(['type' => 'timestamp with time zone', 'default' => 'now()'], $column());

        $epoch = static fn (Uuid $libraryId): int => (int) $connection->fetchOne(
            'SELECT extract(epoch FROM granted_at)::bigint FROM user_library_access WHERE library_id = :id',
            ['id' => $libraryId->toString()],
        );
        $utc = new \DateTimeZone('UTC');
        $writtenInstant = (new \DateTimeImmutable($writtenText, $utc))->getTimestamp();
        self::assertSame($writtenInstant, $epoch($written));
        self::assertSame($transactionStart, $epoch($defaulted));
        foreach ($boundaries as $wallClock => $libraryId) {
            self::assertSame((new \DateTimeImmutable($wallClock, $utc))->getTimestamp(), $epoch($libraryId), $wallClock);
        }

        $this->manager->clear();
        $hydrated = $this->manager->getRepository(UserLibraryAccessEntity::class)->findOneBy(['userId' => $owner, 'library' => $written]);
        self::assertInstanceOf(UserLibraryAccessEntity::class, $hydrated);
        self::assertSame($writtenInstant, $hydrated->getGrantedAt()->getTimestamp());

        // A new grant without an explicit time records the current instant, fractions included.
        $connection->executeStatement("SET LOCAL TimeZone = 'Europe/Copenhagen'");
        $fresh = $this->createLibrary();
        self::assertTrue((bool) $connection->fetchOne(
            'INSERT INTO user_library_access (user_id, library_id) VALUES (:user, :library) RETURNING granted_at = now()',
            ['user' => $owner->toString(), 'library' => $fresh->toString()],
        ));
    }

    private function rowCount(string $predicate, Uuid $id): int
    {
        return (int) $this->manager->getConnection()->fetchOne(
            'SELECT count(*) FROM user_library_access WHERE ' . $predicate,
            ['id' => $id->toString()],
        );
    }

    private function createUser(): Uuid
    {
        $id = Uuid::generate();
        $this->manager->persist(new UserEntity(
            new PublicId(),
            'Library access owner',
            $id->toString() . '@baander.app',
            'unused-test-password',
            '',
            $id,
        ));
        $this->manager->flush();

        return $id;
    }

    private function createLibrary(): Uuid
    {
        $suffix = bin2hex(random_bytes(6));
        $library = new LibraryEntity('Access ' . $suffix, 'access-' . $suffix, '/media/access-' . $suffix, 'music', 'local');
        $this->manager->persist($library);
        $this->manager->flush();

        return $library->getId();
    }
}
