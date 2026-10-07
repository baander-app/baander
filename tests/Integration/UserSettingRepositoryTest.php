<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Platform\BaanderDriverMiddleware;
use App\UserPreference\Infrastructure\Doctrine\Entity\UserSettingEntity;
use App\UserPreference\Infrastructure\Doctrine\UserSettingRepository;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use DoctrineMigrations\Version20261007120000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The DBAL user settings store on the migrated disposable PostgreSQL database.
 *
 * Tests run in the harness's rolled-back transaction, except the caller-transaction test,
 * which commits through the production transaction port and deletes its users afterwards.
 */
final class UserSettingRepositoryTest extends TestCase
{
    use OwnershipPersistenceHarness {
        setUp as private setUpHarness;
    }

    private UserSettingRepository $store;

    protected function setUp(): void
    {
        $this->setUpHarness();
        $connection = $this->kernel->getContainer()->get('test.service_container')->get(Connection::class);
        self::assertSame($this->manager->getConnection(), $connection, 'the autowired connection is the entity manager\'s');
        $this->store = new UserSettingRepository($connection);
    }

    public function testChoicesRoundTripWithTheirExactTypes(): void
    {
        $user = $this->createUser();
        $choices = [
            'test.bitrate' => 192,
            'test.bitrate_text' => '192',
            'test.disabled' => false,
            'test.enabled' => true,
            'test.language' => 'da',
            'test.zero' => 0,
        ];

        foreach ($choices as $key => $value) {
            $this->store->save($user, $key, $value);
        }

        self::assertSame($choices, $this->store->findAll($user));
        foreach ($choices as $key => $value) {
            self::assertSame($value, $this->store->find($user, $key), $key);
        }

        $this->store->delete($user, 'test.language');

        self::assertNull($this->store->find($user, 'test.language'));
        self::assertArrayNotHasKey('test.language', $this->store->findAll($user));
        self::assertSame(192, $this->store->find($user, 'test.bitrate'));
    }

    public function testAnAbsentChoiceReadsAsNullAndDeletingItSucceeds(): void
    {
        $user = $this->createUser();

        self::assertNull($this->store->find($user, 'test.language'));
        self::assertSame([], $this->store->findAll($user));

        $this->store->delete($user, 'test.language');

        self::assertSame([], $this->store->findAll($user));
    }

    public function testSavingAKeyAgainKeepsOneRowWithTheLastValue(): void
    {
        $user = $this->createUser();
        $this->store->save($user, 'test.language', 'da');
        $this->manager->getConnection()->executeStatement(
            "UPDATE user_settings SET updated_at = '2026-01-01 00:00:00+00' WHERE user_id = :user_id",
            ['user_id' => $user->toString()],
        );

        $this->store->save($user, 'test.language', 'en');

        self::assertSame('en', $this->store->find($user, 'test.language'));
        self::assertSame(
            [['value' => '"en"', 'touched' => true]],
            $this->manager->getConnection()->fetchAllAssociative(
                "SELECT value::text AS value, updated_at > '2026-01-01 00:00:00+00' AS touched FROM user_settings WHERE user_id = :user_id",
                ['user_id' => $user->toString()],
            ),
        );
    }

    public function testEachUsersChoicesAreIsolated(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();
        $this->store->save($first, 'test.language', 'da');
        $this->store->save($second, 'test.language', 'en');
        $this->store->save($second, 'test.enabled', true);

        $this->store->delete($first, 'test.enabled');
        $this->store->delete($second, 'test.language');

        self::assertSame(['test.language' => 'da'], $this->store->findAll($first));
        self::assertSame(['test.enabled' => true], $this->store->findAll($second));
    }

    public function testDeletingAUserDeletesTheirChoices(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        foreach ([$owner, $other] as $user) {
            $this->store->save($user, 'test.language', 'da');
            $this->store->save($user, 'test.enabled', false);
        }

        $this->deleteUser($owner);

        self::assertSame(0, $this->countOwnedRows('user_settings', 'user_id', $owner));
        self::assertSame(2, $this->countOwnedRows('user_settings', 'user_id', $other));
    }

    public function testAChoiceNeedsAnExistingUser(): void
    {
        $connection = $this->manager->getConnection();
        $connection->executeStatement('SAVEPOINT missing_user');
        try {
            $this->store->save(Uuid::generate(), 'test.language', 'da');
            self::fail('A choice for a user that does not exist must violate fk_user_settings_user_id.');
        } catch (ForeignKeyConstraintViolationException) {
            $connection->executeStatement('ROLLBACK TO SAVEPOINT missing_user');
        }

        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM user_settings'));
    }

    #[SkipDatabaseRollback]
    public function testASaveCommitsOrRollsBackWithTheCallersTransaction(): void
    {
        $connection = $this->manager->getConnection();
        // This test commits, so it leaves the harness transaction and restores one for tearDown.
        $connection->rollBack();
        self::assertSame(0, $connection->getTransactionNestingLevel(), 'DAMA must not wrap this commit-visibility test.');
        $transaction = $this->kernel->getContainer()->get('test.service_container')->get(TransactionPortInterface::class);
        self::assertInstanceOf(TransactionPortInterface::class, $transaction);
        $observer = $this->independentConnection();
        $rolledBack = Uuid::generate();
        $committed = Uuid::generate();

        try {
            // Had the error not propagated, the rollback checks below would find committed rows.
            try {
                $transaction->run(function () use ($rolledBack, $observer): void {
                    $this->registerUser($rolledBack);
                    $this->store->save($rolledBack, 'test.language', 'da');
                    self::assertSame('da', $this->store->find($rolledBack, 'test.language'));
                    self::assertSame(0, $this->observedChoices($observer, $rolledBack));
                    throw new \RuntimeException('Registration failed after the language was seeded.');
                });
            } catch (\RuntimeException $exception) {
                self::assertSame('Registration failed after the language was seeded.', $exception->getMessage());
            }

            self::assertNull($this->store->find($rolledBack, 'test.language'));
            self::assertSame(0, $this->observedChoices($observer, $rolledBack));
            self::assertSame(0, (int) $observer->fetchOne('SELECT count(*) FROM users WHERE id = :id', ['id' => $rolledBack->toString()]));

            $transaction->run(function () use ($committed, $observer): void {
                $this->registerUser($committed);
                $this->store->save($committed, 'test.language', 'da');
                self::assertSame(0, $this->observedChoices($observer, $committed), 'the store does not commit on its own');
            });

            self::assertSame(1, $this->observedChoices($observer, $committed));
        } finally {
            $observer->executeStatement(
                'DELETE FROM users WHERE id IN (:rolled_back, :committed)',
                ['rolled_back' => $rolledBack->toString(), 'committed' => $committed->toString()],
            );
            $observer->close();
            $connection->beginTransaction();
        }
    }

    public function testTheCatalogMatchesTheMappingAndKtd2(): void
    {
        $connection = $this->manager->getConnection();
        self::assertSame([
            'key' => ['data_type' => 'text', 'is_nullable' => 'NO', 'column_default' => null],
            'updated_at' => ['data_type' => 'timestamp with time zone', 'is_nullable' => 'NO', 'column_default' => 'now()'],
            'user_id' => ['data_type' => 'uuid', 'is_nullable' => 'NO', 'column_default' => null],
            'value' => ['data_type' => 'jsonb', 'is_nullable' => 'NO', 'column_default' => null],
        ], $connection->fetchAllAssociativeIndexed(
            "SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = 'user_settings' ORDER BY column_name",
        ));
        self::assertSame([
            'chk_user_settings_value_scalar' => "CHECK ((jsonb_typeof(value) = ANY (ARRAY['boolean'::text, 'number'::text, 'string'::text])))",
            'fk_user_settings_user_id' => 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
            'user_settings_pkey' => 'PRIMARY KEY (user_id, key)',
        ], $connection->fetchAllKeyValue(
            // PostgreSQL 18 also lists each NOT NULL column as a constraint; information_schema covers those.
            "SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'user_settings'::regclass AND contype <> 'n' ORDER BY conname",
        ));

        $this->assertScalarUuidOwners([UserSettingEntity::class => ['userId', 'user_id']]);
        $this->assertDeclaredForeignKeysMatchCatalog(['user_settings' => ['user_id', 'fk_user_settings_user_id']]);
        $this->assertSchemaComparisonIsClean(['user_settings']);
    }

    public function testAJsonNullCannotPoseAsAChoice(): void
    {
        $user = $this->createUser();
        $connection = $this->manager->getConnection();
        foreach (['null', '{}', '[]'] as $value) {
            $connection->executeStatement('SAVEPOINT non_scalar');
            try {
                $connection->executeStatement(
                    "INSERT INTO user_settings (user_id, key, value) VALUES (:user_id, 'test.language', CAST(:value AS jsonb))",
                    ['user_id' => $user->toString(), 'value' => $value],
                );
                self::fail($value . ' must violate chk_user_settings_value_scalar.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState(), $value);
                $connection->executeStatement('ROLLBACK TO SAVEPOINT non_scalar');
            }
        }

        self::assertSame([], $this->store->findAll($user));
    }

    public function testTheMigrationRoundTrips(): void
    {
        $connection = $this->manager->getConnection();
        $user = $this->createUser();
        $this->store->save($user, 'test.language', 'da');

        $this->migrate('down');
        self::assertNull($connection->fetchOne("SELECT to_regclass('user_settings')"));

        $this->migrate('up');
        self::assertSame([], $this->store->findAll($user));
        $this->store->save($user, 'test.language', 'en');
        self::assertSame(['test.language' => 'en'], $this->store->findAll($user));
        $this->assertSchemaComparisonIsClean(['user_settings']);
    }

    private function registerUser(Uuid $id): void
    {
        $this->manager->persist(new UserEntity(
            new PublicId(),
            'Settings test user',
            'settings-' . $id->toString() . '@baander.app',
            'unused-test-password',
            '',
            $id,
        ));
        $this->manager->flush();
    }

    private function observedChoices(Connection $observer, Uuid $user): int
    {
        return (int) $observer->fetchOne('SELECT count(*) FROM user_settings WHERE user_id = :user_id', ['user_id' => $user->toString()]);
    }

    private function migrate(string $direction): void
    {
        $connection = $this->manager->getConnection();
        require_once dirname(__DIR__, 2) . '/migrations/Version20261007120000.php';
        $migration = new Version20261007120000($connection, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function independentConnection(): Connection
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        self::assertIsString($url);
        $configuration = new Configuration();
        $configuration->setMiddlewares([new BaanderDriverMiddleware()]);

        return DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url), $configuration);
    }
}
