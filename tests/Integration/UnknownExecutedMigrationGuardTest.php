<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\Migrations\DependencyFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Doctrine Migrations only warns, non-interactively, about executed versions that are missing
 * from the migration directory, then migrates anyway. A database with such a history (renamed
 * or foreign migrations) is not migrated.
 */
final class UnknownExecutedMigrationGuardTest extends TestCase
{
    use OwnershipPersistenceHarness;

    public function testMigrateRefusesADatabaseWithAnExecutedVersionMissingFromTheDirectory(): void
    {
        $connection = $this->manager->getConnection();
        $migrations = $this->kernel->getContainer()->get('test.service_container')->get('doctrine.migrations.dependency_factory');
        self::assertInstanceOf(DependencyFactory::class, $migrations);
        $latest = (string) $migrations->getMigrationPlanCalculator()->getMigrations()->getLast()->getVersion();
        // The latest migration becomes pending, and the history names one the directory lacks.
        $connection->executeStatement('DELETE FROM doctrine_migration_versions WHERE version = ?', [$latest]);
        $connection->executeStatement(
            "INSERT INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\\Version20260701000000Renamed', '2026-07-01 00:00:00', 1)",
        );

        $failure = null;
        try {
            $this->migrate();
        } catch (\RuntimeException $exception) {
            $failure = $exception->getMessage();
        }

        self::assertNotNull($failure, 'migrations:migrate must not run with an unknown executed version.');
        self::assertStringContainsString('DoctrineMigrations\\Version20260701000000Renamed', $failure);
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM doctrine_migration_versions WHERE version = ?', [$latest]));
    }

    private function migrate(): int
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('doctrine:migrations:migrate'));

        return $tester->execute([], ['interactive' => false]);
    }
}
