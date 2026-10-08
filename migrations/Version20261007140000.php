<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007140000 extends AbstractMigration
{
    /** The seeded health check; down() removes exactly this schedule. */
    public const string HEALTH_CHECK_JOB_ID = '0199bf3c-8a00-7000-8000-0a07c0de9e03';
    public const string HEALTH_CHECK_COMMAND = 'App\\Shared\\Application\\Command\\CheckHealthCommand';
    public const string HEALTH_CHECK_EXPRESSION = '*/5 * * * *';

    public function getDescription(): string
    {
        return 'Schedule the five-minute health check, which alerts administrators of a degradation while notifications.admin_alerts is on.';
    }

    public function up(Schema $schema): void
    {
        // Every five minutes; next_run_at is the next five-minute boundary in UTC. The scheduler
        // starts evaluating a new schedule from its first poll (evaluated_through stays NULL),
        // so no run is caught up for the past.
        $this->addSql(
            <<<'SQL'
                INSERT INTO scheduled_jobs (id, revision, name, expression, job_type, command, status, description, parameters, created_at, updated_at, next_run_at, run_count)
                VALUES (:id, gen_random_uuid(), :name, :expression, 'messenger', :command, 'active', :description, '[]', clock_timestamp(), clock_timestamp(),
                    date_bin(INTERVAL '5 minutes', clock_timestamp(), TIMESTAMPTZ '2001-01-01 00:00:00+00') + INTERVAL '5 minutes',
                    0)
                ON CONFLICT (id) DO NOTHING
                SQL,
            [
                'id' => self::HEALTH_CHECK_JOB_ID,
                'name' => 'Check system health',
                'expression' => self::HEALTH_CHECK_EXPRESSION,
                'command' => self::HEALTH_CHECK_COMMAND,
                'description' => 'Checks system health and alerts administrators when a component degrades, while the notifications.admin_alerts setting is on.',
            ],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM scheduled_jobs WHERE id = :id', ['id' => self::HEALTH_CHECK_JOB_ID]);
    }
}
