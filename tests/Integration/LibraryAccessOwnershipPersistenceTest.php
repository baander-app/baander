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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

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

        $expected = $schema->getTable('user_library_access')->getForeignKey('fk_user_library_access_user_id');
        self::assertSame(['user_id'], $expected->getLocalColumns());
        self::assertSame('users', $expected->getForeignTableName());
        self::assertSame(['id'], $expected->getForeignColumns());
        self::assertSame('CASCADE', $expected->onDelete());

        $actual = $catalog->getForeignKey('fk_user_library_access_user_id');
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
