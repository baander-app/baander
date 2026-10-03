<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Permanent occurrence admission and per-job serialization until exact wrapper return. */
final class Version20261003010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Consume scheduler occurrences once and serialize unresolved wrapper invocations per job.';
    }

    public function up(Schema $schema): void
    {
        // A composite reference prevents an execution from borrowing another job's slot.
        $this->addSql('ALTER TABLE scheduler_occurrences ADD CONSTRAINT scheduler_occurrences_id_job_uidx UNIQUE (id, job_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE scheduler_occurrence_executions (
                occurrence_id UUID PRIMARY KEY,
                job_id UUID NOT NULL,
                attempt_id UUID NOT NULL UNIQUE,
                deployment_namespace TEXT NOT NULL CHECK (deployment_namespace ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$'),
                deployment_boot_id TEXT NOT NULL CHECK (deployment_boot_id ~ '^[0-9a-f]{32}$'),
                deployment_epoch BIGINT NOT NULL CHECK (deployment_epoch > 0),
                started_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
                returned_at TIMESTAMPTZ DEFAULT NULL,
                CONSTRAINT scheduler_occurrence_executions_occurrence_job_fk
                    FOREIGN KEY (occurrence_id, job_id) REFERENCES scheduler_occurrences(id, job_id) ON DELETE RESTRICT
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX scheduler_occurrence_executions_unresolved_job_uidx ON scheduler_occurrence_executions (job_id) WHERE returned_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Dropping permanent execution admissions would authorize duplicate scheduler effects.');
    }
}
