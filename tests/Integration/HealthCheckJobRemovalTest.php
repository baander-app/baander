<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use DoctrineMigrations\Version20261007140000;
use DoctrineMigrations\Version20261010110000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Version20261010110000 removes the five-minute health check seeded by Version20261007140000,
 * on the fully migrated disposable PostgreSQL inside one rolled-back transaction.
 */
final class HealthCheckJobRemovalTest extends TestCase
{
    use OwnershipPersistenceHarness;


    /** Migration classes are not autoloaded. */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/migrations/Version20261007140000.php';
        require_once dirname(__DIR__, 2) . '/migrations/Version20261010110000.php';
    }

    public function testFullyMigratedDatabaseHasNoHealthCheckJob(): void
    {
        self::assertSame(1, (int) $this->manager->getConnection()->fetchOne(
            'SELECT count(*) FROM doctrine_migration_versions WHERE version = ?',
            ['DoctrineMigrations\\Version20261010110000'],
        ));
        self::assertFalse($this->healthCheckJobExists());

        $jobs = $this->kernel->getContainer()->get('test.service_container')->get(ScheduledJobPortInterface::class);
        self::assertInstanceOf(ScheduledJobPortInterface::class, $jobs);
        self::assertNull($jobs->getById(Uuid::fromString(Version20261007140000::HEALTH_CHECK_JOB_ID)));
    }

    public function testMigrationRemovesTheSeededJobAndNoOtherSchedule(): void
    {
        $otherJobs = $this->otherJobIds();
        $this->runMigration(new Version20261007140000($this->manager->getConnection(), new NullLogger()), 'up');
        self::assertTrue($this->healthCheckJobExists());

        $this->runMigration(new Version20261010110000($this->manager->getConnection(), new NullLogger()), 'up');

        self::assertFalse($this->healthCheckJobExists());
        self::assertSame($otherJobs, $this->otherJobIds());
    }

    public function testMigrationCannotBeReverted(): void
    {
        $this->expectException(IrreversibleMigration::class);
        $this->runMigration(new Version20261010110000($this->manager->getConnection(), new NullLogger()), 'down');
    }

    public function testSchedulerNoLongerOffersTheHealthCheckCommand(): void
    {
        $registry = $this->kernel->getContainer()->get('test.service_container')->get(SchedulerRegistry::class);
        self::assertInstanceOf(SchedulerRegistry::class, $registry);
        self::assertFalse($registry->isMessengerCommandAllowed('App\\Shared\\Application\\Command\\CheckHealthCommand'));
    }

    private function healthCheckJobExists(): bool
    {
        return $this->manager->getConnection()->fetchOne(
            'SELECT 1 FROM scheduled_jobs WHERE id = ?',
            [Version20261007140000::HEALTH_CHECK_JOB_ID],
        ) !== false;
    }

    /** @return list<string> */
    private function otherJobIds(): array
    {
        return array_map(
            static fn (mixed $id): string => (string) $id,
            $this->manager->getConnection()->fetchFirstColumn(
                'SELECT id FROM scheduled_jobs WHERE id <> ? ORDER BY id',
                [Version20261007140000::HEALTH_CHECK_JOB_ID],
            ),
        );
    }

    private function runMigration(AbstractMigration $migration, string $direction): void
    {
        $connection = $this->manager->getConnection();
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
