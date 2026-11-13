<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Session\Infrastructure\Doctrine\Entity\ListeningSessionEntity;
use App\Session\Infrastructure\Doctrine\Entity\PlaybackCommandEntity;
use App\Session\Infrastructure\Doctrine\Entity\PlaybackDestinationEntity;
use App\Session\Infrastructure\Doctrine\Entity\PlaybackDestinationIncarnationEntity;
use App\Session\Infrastructure\Doctrine\Entity\PlaybackTransferEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Entity\SystemSettingEntity;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * The playback coordination tables are mapped exactly as the migrations created them.
 *
 * Reads the catalog of the fully migrated disposable PostgreSQL database. Schema comparison
 * sees neither check constraints nor a partial index's meaning, so those are read directly.
 */
final class PlaybackSchemaMappingTest extends TestCase
{
    use OwnershipPersistenceHarness;

    /** @var array<string, class-string> table => entity */
    private const array TABLES = [
        'playback_destinations' => PlaybackDestinationEntity::class,
        'playback_destination_incarnations' => PlaybackDestinationIncarnationEntity::class,
        'playback_transfers' => PlaybackTransferEntity::class,
        'playback_commands' => PlaybackCommandEntity::class,
    ];

    /** @var array<string, array{string, list<string>, string, list<string>, string|null}> constraint => [table, columns, referenced table, referenced columns, delete rule] */
    private const array FOREIGN_KEYS = [
        'fk_listening_sessions_selected_destination_id_user_id' => ['listening_sessions', ['selected_destination_id', 'user_id'], 'playback_destinations', ['id', 'user_id'], null],
        'fk_listening_sessions_active_incarnation_id_user_id' => ['listening_sessions', ['active_incarnation_id', 'user_id'], 'playback_destination_incarnations', ['id', 'user_id'], null],
        'fk_playback_destinations_user_id' => ['playback_destinations', ['user_id'], 'users', ['id'], 'CASCADE'],
        'fk_playback_destination_incarnations_destination_id_user_id' => ['playback_destination_incarnations', ['destination_id', 'user_id'], 'playback_destinations', ['id', 'user_id'], 'CASCADE'],
        'fk_playback_transfers_session_id_user_id' => ['playback_transfers', ['session_id', 'user_id'], 'listening_sessions', ['id', 'user_id'], 'CASCADE'],
        'fk_playback_transfers_source_incarnation_id_user_id' => ['playback_transfers', ['source_incarnation_id', 'user_id'], 'playback_destination_incarnations', ['id', 'user_id'], null],
        'fk_playback_transfers_target_incarnation_id_user_id' => ['playback_transfers', ['target_incarnation_id', 'user_id'], 'playback_destination_incarnations', ['id', 'user_id'], null],
        'fk_playback_commands_session_id_user_id' => ['playback_commands', ['session_id', 'user_id'], 'listening_sessions', ['id', 'user_id'], 'CASCADE'],
        'fk_playback_commands_incarnation_id_user_id' => ['playback_commands', ['incarnation_id', 'user_id'], 'playback_destination_incarnations', ['id', 'user_id'], null],
    ];

    public function testPlaybackTablesAreMappedReadOnlyWithScalarReferences(): void
    {
        foreach (self::TABLES as $table => $entity) {
            $metadata = $this->manager->getClassMetadata($entity);
            self::assertSame($table, $metadata->getTableName());
            self::assertTrue($metadata->isReadOnly, $entity . ' is never flushed as a change');
            self::assertSame([], $metadata->getAssociationNames(), $entity . ' holds every reference as a scalar column');
            foreach ($metadata->getFieldNames() as $field) {
                $column = $metadata->getColumnName($field);
                if ($column === 'id' || (str_ends_with($column, '_id') && $column !== 'public_id')) {
                    self::assertSame('uuid', $metadata->getTypeOfField($field), $entity . '::$' . $field);
                }
            }
        }
    }

    public function testSchemaComparisonSeesThePlaybackTablesAndPlansNothingForThem(): void
    {
        $visible = array_map(
            static fn (OptionallyQualifiedName $name): string => $name->getUnqualifiedName()->getValue(),
            $this->manager->getConnection()->createSchemaManager()->introspectTableNames(),
        );
        foreach (array_keys(self::TABLES) as $table) {
            self::assertContains($table, $visible, $table . ' is no longer hidden from schema comparison');
        }

        $this->assertSchemaComparisonIsClean(['listening_sessions', ...array_keys(self::TABLES)]);
    }

    public function testDeclaredForeignKeysMatchTheCatalog(): void
    {
        $mapped = (new SchemaTool($this->manager))->getSchemaFromMetadata($this->manager->getMetadataFactory()->getAllMetadata());
        $catalog = $this->manager->getConnection()->createSchemaManager();

        foreach (self::FOREIGN_KEYS as $name => [$table, $columns, $foreignTable, $foreignColumns, $onDelete]) {
            self::assertTrue($mapped->getTable($table)->hasForeignKey($name), $table . ' declares ' . $name);
            foreach ([$mapped->getTable($table)->getForeignKey($name), $catalog->introspectTable($table)->getForeignKey($name)] as $foreignKey) {
                self::assertSame($columns, $foreignKey->getLocalColumns(), $name);
                self::assertSame($foreignTable, $foreignKey->getForeignTableName(), $name);
                self::assertSame($foreignColumns, $foreignKey->getForeignColumns(), $name);
                self::assertSame($onDelete, $foreignKey->onDelete(), $name);
            }
        }

        // Every foreign key the catalog holds on these tables is one of the declared ones.
        $inCatalog = $this->manager->getConnection()->fetchFirstColumn(<<<'SQL'
            SELECT c.conname FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid
             WHERE t.relnamespace = current_schema()::regnamespace AND c.contype = 'f'
               AND (t.relname = ANY (string_to_array(:tables, ',')) OR (t.relname = 'listening_sessions' AND array_length(c.conkey, 1) > 1))
             ORDER BY 1
            SQL, ['tables' => implode(',', array_keys(self::TABLES))]);
        $declared = array_keys(self::FOREIGN_KEYS);
        sort($declared);
        self::assertSame($declared, $inCatalog);
    }

    public function testCheckConstraintsStayInTheCatalog(): void
    {
        $named = $this->manager->getConnection()->fetchFirstColumn(<<<'SQL'
            SELECT c.conname FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid
             WHERE t.relnamespace = current_schema()::regnamespace AND c.contype = 'c' AND c.convalidated
            SQL);
        foreach ([
            'chk_playback_destination_incarnations_local_recovery_authority',
            'chk_playback_commands_authority',
            'chk_playback_commands_rejection_reason',
            'chk_playback_commands_settled_revision',
        ] as $name) {
            self::assertContains($name, $named);
        }

        // A check written beside its column constrains exactly that column.
        $checked = $this->manager->getConnection()->fetchFirstColumn(<<<'SQL'
            SELECT t.relname || '.' || a.attname
              FROM pg_constraint c
              JOIN pg_class t ON t.oid = c.conrelid
              JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
             WHERE t.relnamespace = current_schema()::regnamespace AND c.contype = 'c' AND c.convalidated
               AND array_length(c.conkey, 1) = 1
            SQL);
        foreach ([
            'playback_destination_incarnations.epoch',
            'playback_destination_incarnations.observed_volume',
            'playback_destination_incarnations.position',
            'playback_transfers.state',
            'playback_transfers.desired_state',
            'playback_transfers.position',
            'playback_commands.type',
        ] as $column) {
            self::assertContains($column, $checked);
        }
    }

    public function testPartialIndexesKeepTheirPredicates(): void
    {
        $indexes = $this->manager->getConnection()->fetchAllAssociativeIndexed(<<<'SQL'
            SELECT ic.relname, t.relname AS table_name, i.indisunique AS is_unique, i.indisvalid AS is_valid,
                   pg_get_expr(i.indpred, i.indrelid) AS predicate
              FROM pg_index i
              JOIN pg_class ic ON ic.oid = i.indexrelid
              JOIN pg_class t ON t.oid = i.indrelid
             WHERE t.relnamespace = current_schema()::regnamespace AND i.indpred IS NOT NULL
               AND t.relname = ANY (string_to_array(:tables, ','))
            SQL, ['tables' => implode(',', array_keys(self::TABLES))]);

        self::assertSame([
            'idx_playback_commands_pending_controls' => ['playback_commands', false, "((acknowledged_at IS NULL) AND (type = ANY (ARRAY['play'::text, 'pause'::text, 'seek'::text, 'load'::text, 'stop'::text, 'volume'::text, 'queue'::text])))"],
            'idx_playback_commands_replay' => ['playback_commands', false, '(acknowledged_at IS NULL)'],
            'uniq_playback_transfers_current' => ['playback_transfers', true, "(state <> ALL (ARRAY['committed'::text, 'failed'::text]))"],
        ], $this->sortedByKey(array_map(static function (array $index): array {
            self::assertTrue((bool) $index['is_valid']);

            return [(string) $index['table_name'], (bool) $index['is_unique'], (string) $index['predicate']];
        }, $indexes)));

        // The mapping states the same uniqueness and predicate, so a created schema fences the same rows.
        $mapped = (new SchemaTool($this->manager))->getSchemaFromMetadata($this->manager->getMetadataFactory()->getAllMetadata());
        foreach ($indexes as $name => $index) {
            $declared = $mapped->getTable((string) $index['table_name'])->getIndex($name);
            self::assertSame((bool) $index['is_unique'], $declared->isUnique(), $name);
            self::assertSame($index['predicate'], $declared->getOption('where'), $name);
        }
    }

    public function testTheListenerAddsAKeyOnlyToATableInTheSchema(): void
    {
        $tool = new SchemaTool($this->manager);

        $unrelated = $tool->getSchemaFromMetadata([$this->manager->getClassMetadata(SystemSettingEntity::class)]);
        self::assertSame([], $this->foreignKeyNames($unrelated));

        $transfers = $tool->getSchemaFromMetadata([$this->manager->getClassMetadata(PlaybackTransferEntity::class)]);
        self::assertSame([
            'fk_playback_transfers_session_id_user_id',
            'fk_playback_transfers_source_incarnation_id_user_id',
            'fk_playback_transfers_target_incarnation_id_user_id',
        ], $this->foreignKeyNames($transfers));

        $sessions = $tool->getSchemaFromMetadata([$this->manager->getClassMetadata(ListeningSessionEntity::class)]);
        self::assertSame([
            'fk_listening_sessions_active_incarnation_id_user_id',
            'fk_listening_sessions_selected_destination_id_user_id',
            'fk_listening_sessions_user_id',
        ], $this->foreignKeyNames($sessions));
    }

    public function testEachEntityInsertsARowTheCheckConstraintsAccept(): void
    {
        $owner = $this->createUser();
        $session = new ListeningSessionEntity($owner);
        $destination = Uuid::generate();
        $incarnation = Uuid::generate();
        $now = new \DateTimeImmutable('2026-11-10 12:00:00.123456+00:00');
        $later = $now->modify('+30 seconds');
        $recoveryExpiry = $now->modify('+30 days');

        $this->manager->persist($session);
        $this->manager->persist(new PlaybackDestinationEntity($destination, new PublicId(), $owner, Uuid::generate(), Uuid::generate(), 'Kitchen', ['song', 'volume'], $now));
        $this->manager->flush();
        $this->manager->persist(new PlaybackDestinationIncarnationEntity($incarnation, $destination, $owner, 1, hash('sha256', 'capability'), $later, true, Uuid::generate(), hash('sha256', 'credential'), $recoveryExpiry));
        $this->manager->persist(new PlaybackDestinationIncarnationEntity(Uuid::generate(), $destination, $owner, 2, hash('sha256', 'other capability'), $later));
        $this->manager->flush();
        $this->manager->persist(new PlaybackTransferEntity(Uuid::generate(), $session->getId(), $owner, Uuid::generate(), 'digest', null, $incarnation, 'preparing', 'playing', 12.5, 1, $later, $now));
        $this->manager->persist(new PlaybackCommandEntity(Uuid::generate(), $session->getId(), $owner, Uuid::generate(), Uuid::generate(), 'digest', $incarnation, 1, 1, 'prepare', ['position' => 12.5], null, null, $later, $now));
        $this->manager->persist(new PlaybackCommandEntity(Uuid::generate(), $session->getId(), $owner, Uuid::generate(), Uuid::generate(), 'digest', null, null, 1, 'queue', ['queue' => []], 'retained', $now, $later, $now));
        $this->manager->flush();
        $this->manager->clear();

        $connection = $this->manager->getConnection();
        $row = $connection->fetchAssociative('SELECT kind, capabilities::text AS capabilities, created_at FROM playback_destinations WHERE id = :id', ['id' => $destination->toString()]);
        self::assertIsArray($row);
        self::assertSame('browser', $row['kind']);
        self::assertSame(['song', 'volume'], json_decode((string) $row['capabilities'], true, flags: JSON_THROW_ON_ERROR));
        self::assertEquals($now, new \DateTimeImmutable((string) $row['created_at']));

        $row = $connection->fetchAssociative(<<<'SQL'
            SELECT observed_state, position, observed_volume, retired_at, local_recovery_eligible, recovery_expires_at, recovery_result
              FROM playback_destination_incarnations WHERE id = :id
            SQL, ['id' => $incarnation->toString()]);
        self::assertIsArray($row);
        self::assertSame('unknown', $row['observed_state']);
        self::assertEquals(0.0, (float) $row['position']);
        self::assertNull($row['observed_volume']);
        self::assertNull($row['retired_at']);
        self::assertTrue((bool) $row['local_recovery_eligible']);
        self::assertEquals($recoveryExpiry, new \DateTimeImmutable((string) $row['recovery_expires_at']));
        self::assertNull($row['recovery_result']);

        self::assertSame(1, $this->countOwnedRows('playback_transfers', 'user_id', $owner));
        self::assertSame(2, $this->countOwnedRows('playback_commands', 'user_id', $owner));
        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT count(*) FROM playback_commands WHERE user_id = :owner AND incarnation_id IS NULL AND outcome = 'retained' AND acknowledged_at IS NOT NULL",
            ['owner' => $owner->toString()],
        ));

        // The owner key cascades through the session and the destination to every playback row.
        $this->deleteUser($owner);
        foreach (array_keys(self::TABLES) as $table) {
            self::assertSame(0, $this->countOwnedRows($table, 'user_id', $owner), $table);
        }
    }

    /** @return list<string> */
    private function foreignKeyNames(Schema $schema): array
    {
        $names = [];
        foreach ($schema->getTables() as $table) {
            foreach ($table->getForeignKeys() as $foreignKey) {
                $names[] = $foreignKey->getName();
            }
        }
        sort($names);

        return $names;
    }

    /**
     * @template T
     *
     * @param array<string, T> $values
     *
     * @return array<string, T>
     */
    private function sortedByKey(array $values): array
    {
        ksort($values);

        return $values;
    }
}
