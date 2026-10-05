<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Kernel;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Domain\Model\AudioPreferences;
use App\UserPreference\Domain\Model\EqDeviceProfile;
use App\UserPreference\Domain\Model\LayoutPreferences;
use App\UserPreference\Domain\Model\PlayerPreferences;
use App\UserPreference\Domain\Model\PreferenceHistory;
use App\UserPreference\Domain\Model\SidebarConfig;
use App\UserPreference\Infrastructure\Doctrine\Entity\AudioPreferencesEntity;
use App\UserPreference\Infrastructure\Doctrine\Entity\EqDeviceProfileEntity;
use App\UserPreference\Infrastructure\Doctrine\Entity\LayoutPreferencesEntity;
use App\UserPreference\Infrastructure\Doctrine\Entity\PlayerPreferencesEntity;
use App\UserPreference\Infrastructure\Doctrine\Entity\PreferenceHistoryEntity;
use App\UserPreference\Infrastructure\Doctrine\Entity\SidebarConfigEntity;
use App\UserPreference\Infrastructure\Doctrine\Entity\UserAccentColorEntity;
use App\UserPreference\Infrastructure\Doctrine\Repository\AccentColorDoctrineRepository;
use App\UserPreference\Infrastructure\Doctrine\Repository\AudioPreferencesDoctrineRepository;
use App\UserPreference\Infrastructure\Doctrine\Repository\EqDeviceProfileDoctrineRepository;
use App\UserPreference\Infrastructure\Doctrine\Repository\LayoutPreferencesDoctrineRepository;
use App\UserPreference\Infrastructure\Doctrine\Repository\PlayerPreferencesDoctrineRepository;
use App\UserPreference\Infrastructure\Doctrine\Repository\PreferenceHistoryDoctrineRepository;
use App\UserPreference\Infrastructure\Doctrine\Repository\SidebarConfigDoctrineRepository;
use App\UserPreference\Infrastructure\Doctrine\VersionedPreferencesWriter;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/** Production mappings and migrated constraints, on disposable PostgreSQL only. */
final class PreferenceOwnershipPersistenceTest extends TestCase
{
    /** @var array<string, class-string> */
    private const ENTITIES = [
        'audio_preferences' => AudioPreferencesEntity::class,
        'player_preferences' => PlayerPreferencesEntity::class,
        'layout_preferences' => LayoutPreferencesEntity::class,
        'user_sidebar_configs' => SidebarConfigEntity::class,
        'user_accent_colors' => UserAccentColorEntity::class,
        'eq_device_profiles' => EqDeviceProfileEntity::class,
        'preference_history' => PreferenceHistoryEntity::class,
    ];

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

    public function testScalarMappingsPreserveCatalogForeignKeysAndSchemaDiff(): void
    {
        $tool = new SchemaTool($this->manager);
        $schema = $tool->getSchemaFromMetadata($this->manager->getMetadataFactory()->getAllMetadata());
        $catalog = $this->manager->getConnection()->createSchemaManager();

        foreach (self::ENTITIES as $table => $entity) {
            $metadata = $this->manager->getClassMetadata($entity);
            self::assertTrue($metadata->hasField('userId'), $entity);
            self::assertFalse($metadata->hasAssociation('user'), $entity);
            self::assertSame('user_id', $metadata->getColumnName('userId'));
            $name = 'fk_' . $table . '_user_id';
            $expected = $schema->getTable($table)->getForeignKey($name);
            self::assertSame(['user_id'], $expected->getLocalColumns());
            self::assertSame('users', $expected->getForeignTableName());
            self::assertSame(['id'], $expected->getForeignColumns());
            self::assertSame('CASCADE', $expected->onDelete());
            $actual = $catalog->introspectTable($table)->getForeignKey($name);
            self::assertSame($actual->getLocalColumns(), $expected->getLocalColumns());
            self::assertSame($actual->getForeignTableName(), $expected->getForeignTableName());
            self::assertSame($actual->onDelete(), $expected->onDelete());
        }

        $sql = implode("\n", $tool->getUpdateSchemaSql($this->manager->getMetadataFactory()->getAllMetadata()));

        foreach (array_keys(self::ENTITIES) as $table) {
            self::assertStringNotContainsString('DROP CONSTRAINT fk_' . $table . '_user_id', $sql);
        }

        // A focused SchemaTool fixture must retain the external users-table contract too.
        $partial = $tool->getSchemaFromMetadata([$this->manager->getClassMetadata(AudioPreferencesEntity::class)]);
        self::assertSame(
            'CASCADE',
            $partial->getTable('audio_preferences')->getForeignKey('fk_audio_preferences_user_id')->onDelete(),
        );
    }

    public function testRepositoryRoundTripsIsolationAndDatabaseCascade(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();

        foreach ([$first, $second] as $userId) {
            (new AudioPreferencesDoctrineRepository($this->manager))->save(
                AudioPreferences::create($userId, ['owner' => $userId->toString()]),
            );
            (new PlayerPreferencesDoctrineRepository($this->manager))->save(
                PlayerPreferences::create($userId, ['owner' => $userId->toString()]),
            );
            (new LayoutPreferencesDoctrineRepository($this->manager))->save(
                LayoutPreferences::create($userId, ['owner' => $userId->toString()]),
            );
            (new SidebarConfigDoctrineRepository($this->manager))->save(SidebarConfig::create($userId, 'music'));
            (new AccentColorDoctrineRepository($this->manager))->setAccentColor($userId, 'violet');
            (new EqDeviceProfileDoctrineRepository($this->manager))->save(
                EqDeviceProfile::create($userId, 'Owner profile', deviceId: 'owner-device', isDefault: true),
            );
            (new PreferenceHistoryDoctrineRepository($this->manager))->save(
                PreferenceHistory::create($userId, 'audio', 1, ['owner' => $userId->toString()]),
            );
        }

        $this->manager->clear();

        foreach ([$first, $second] as $userId) {
            foreach ([
                AudioPreferencesDoctrineRepository::class,
                PlayerPreferencesDoctrineRepository::class,
                LayoutPreferencesDoctrineRepository::class,
            ] as $repository) {
                $model = (new $repository($this->manager))->findByUserId($userId);
                self::assertNotNull($model);
                self::assertSame($userId->toString(), $model->getUserId()->toString());
                self::assertSame(['owner' => $userId->toString()], $model->getPayload());
            }

            $sidebar = (new SidebarConfigDoctrineRepository($this->manager))->findByUserAndMediaType($userId, 'music');
            self::assertNotNull($sidebar);
            self::assertSame($userId->toString(), $sidebar->getUserId()->toString());
            self::assertSame('violet', (new AccentColorDoctrineRepository($this->manager))->getAccentColor($userId));
            $profiles = new EqDeviceProfileDoctrineRepository($this->manager);
            self::assertCount(1, $profiles->findByUserId($userId));
            self::assertSame($userId->toString(), $profiles->findDefaultByUserId($userId)?->getUserId()->toString());
            self::assertSame(
                $userId->toString(),
                $profiles->findByDeviceId($userId, 'owner-device')?->getUserId()->toString(),
            );

            $history = new PreferenceHistoryDoctrineRepository($this->manager);
            self::assertCount(1, $history->findByUserAndType($userId, 'audio'));
            self::assertSame(
                $userId->toString(),
                $history->findByUserAndTypeAndVersion($userId, 'audio', 1)?->getUserId()->toString(),
            );
        }

        $connection = $this->manager->getConnection();
        $connection->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $first->toString()]);

        foreach (array_keys(self::ENTITIES) as $table) {
            $sql = 'SELECT count(*) FROM ' . $table . ' WHERE user_id = :id';

            self::assertSame(
                0,
                (int) $connection->fetchOne($sql, ['id' => $first->toString()]),
            );
            self::assertSame(
                1,
                (int) $connection->fetchOne($sql, ['id' => $second->toString()]),
            );
        }
    }

    public function testVersionedWritesRefreshManagedScalarOwner(): void
    {
        $userId = $this->createUser();
        $writer = new VersionedPreferencesWriter($this->manager->getConnection());
        $repository = new AudioPreferencesDoctrineRepository($this->manager);

        self::assertSame(1, $writer->saveForUser('audio', $userId, ['value' => 'before'], 0));
        $before = $repository->findByUserId($userId);
        self::assertNotNull($before);
        self::assertSame(1, $before->getVersion());

        self::assertSame(2, $writer->saveForUser('audio', $userId, ['value' => 'after'], 1));
        $after = $repository->findByUserId($userId);
        self::assertNotNull($after);
        self::assertSame(['value' => 'after'], $after->getPayload());
        self::assertSame(2, $after->getVersion());
    }

    public function testForeignKeyRejectsAnUnknownScalarOwner(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        (new VersionedPreferencesWriter($this->manager->getConnection()))->saveForUser(
            'audio',
            Uuid::generate(),
            [],
            0,
        );
    }

    private function createUser(): Uuid
    {
        $id = Uuid::generate();
        $entity = new UserEntity(
            new PublicId(),
            'Preference owner',
            $id->toString() . '@baander.app',
            'unused-test-password',
            '',
            $id,
        );

        $this->manager->persist($entity);
        $this->manager->flush();

        return $id;
    }
}
