<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010110000 extends AbstractMigration
{
    /** The five-minute health check seeded by Version20261007140000. */
    private const string HEALTH_CHECK_JOB_ID = '0199bf3c-8a00-7000-8000-0a07c0de9e03';

    public function getDescription(): string
    {
        return 'Remove the five-minute health check job; the web server now runs the health monitor.';
    }

    public function up(Schema $schema): void
    {
        // A plain delete, as an administrator's delete of the schedule would do. Its retained
        // scheduler occurrences stay: they have no foreign key to the schedule, and the scheduler
        // skips an occurrence whose job no longer exists.
        $this->addSql('DELETE FROM scheduled_jobs WHERE id = :id', ['id' => self::HEALTH_CHECK_JOB_ID]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The health check job ran CheckHealthCommand, which no longer exists; restoring the schedule would only fail on every run.');
    }
}
