<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Kernel;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Production kernel, mappings and migrated constraints for user-owned tables mapped with scalar owner IDs.
 *
 * Every test runs inside one rolled-back transaction on disposable PostgreSQL only.
 */
trait OwnershipPersistenceHarness
{
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

    /** @param array<class-string, array{string, string}> $owners entity => [field, column] */
    private function assertScalarUuidOwners(array $owners): void
    {
        foreach ($owners as $entity => [$field, $column]) {
            $metadata = $this->manager->getClassMetadata($entity);
            self::assertTrue($metadata->hasField($field), $entity . '::$' . $field . ' is a mapped field');
            foreach ($metadata->getAssociationNames() as $association) {
                if ($metadata->isAssociationWithSingleJoinColumn($association)) {
                    self::assertNotSame($column, $metadata->getSingleAssociationJoinColumnName($association), $entity);
                }
            }
            self::assertSame($column, $metadata->getColumnName($field), $entity);
            self::assertSame('uuid', $metadata->getTypeOfField($field), $entity);
        }
    }

    /** @param array<string, array{string, string}> $foreignKeys table => [owner column, migration-defined FK name] */
    private function assertDeclaredForeignKeysMatchCatalog(array $foreignKeys): void
    {
        $schema = (new SchemaTool($this->manager))->getSchemaFromMetadata($this->manager->getMetadataFactory()->getAllMetadata());
        $catalog = $this->manager->getConnection()->createSchemaManager();

        foreach ($foreignKeys as $table => [$column, $name]) {
            self::assertTrue($schema->getTable($table)->hasForeignKey($name), $table . ' declares ' . $name);
            $expected = $schema->getTable($table)->getForeignKey($name);
            self::assertSame([$column], $expected->getLocalColumns(), $table);
            self::assertSame('users', $expected->getForeignTableName(), $table);
            self::assertSame(['id'], $expected->getForeignColumns(), $table);
            self::assertSame('CASCADE', $expected->onDelete(), $table);

            $actual = $catalog->introspectTable($table)->getForeignKey($name);
            self::assertSame($actual->getLocalColumns(), $expected->getLocalColumns(), $table);
            self::assertSame($actual->getForeignTableName(), $expected->getForeignTableName(), $table);
            self::assertSame($actual->getForeignColumns(), $expected->getForeignColumns(), $table);
            self::assertSame($actual->onDelete(), $expected->onDelete(), $table);
        }
    }

    /**
     * No planned statement may touch these tables; in particular no DROP CONSTRAINT or DROP INDEX.
     *
     * @param list<string> $tables
     */
    private function assertSchemaComparisonIsClean(array $tables): void
    {
        $tool = new SchemaTool($this->manager);
        $metadata = $this->manager->getMetadataFactory()->getAllMetadata();
        $catalog = $this->manager->getConnection()->createSchemaManager();
        $mapped = $tool->getSchemaFromMetadata($metadata);

        // PostgreSQL's DROP INDEX names only the index, so match every asset on these tables,
        // including DBAL's implicit foreign-key index names on either side of the comparison.
        $names = [];
        foreach ($tables as $table) {
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

        self::assertSame([], array_values(array_filter(
            $statements,
            static fn (string $sql): bool => preg_match('/\bDROP\s+(CONSTRAINT|INDEX)\b/i', $sql) === 1,
        )));
        self::assertSame([], $statements);
    }

    private function createUser(): Uuid
    {
        $id = Uuid::generate();
        $this->manager->persist(new UserEntity(
            new PublicId(),
            'Ownership test owner',
            $id->toString() . '@baander.app',
            'unused-test-password',
            '',
            $id,
        ));
        $this->manager->flush();

        return $id;
    }

    /** Transcode jobs and sessions reference an existing video. */
    private function createVideo(): Uuid
    {
        $video = new VideoEntity(new PublicId(), '/media/ownership-test.mkv', bin2hex(random_bytes(16)));
        $this->manager->persist($video);
        $this->manager->flush();

        return $video->getId();
    }

    private function deleteUser(Uuid $id): void
    {
        $this->manager->getConnection()->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $id->toString()]);
    }

    private function countOwnedRows(string $table, string $column, Uuid $owner): int
    {
        return (int) $this->manager->getConnection()->fetchOne(
            sprintf('SELECT count(*) FROM %s WHERE %s = :owner', $table, $column),
            ['owner' => $owner->toString()],
        );
    }
}
