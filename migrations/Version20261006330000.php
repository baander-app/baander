<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006330000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep one job_monitors row per job_id, and never let a job finish before it started.';
    }

    public function up(Schema $schema): void
    {
        // Block writers until the constraints exist, so no duplicate arrives after the merge.
        // The transactional migration releases the lock on success or failure.
        $this->addSql('LOCK TABLE job_monitors IN SHARE ROW EXCLUSIVE MODE');

        // Each delivery of a retried message added a row with the same job_id, and status
        // updates by job_id rewrote all of them alike. Keep the newest row of each job. It takes
        // the job's first receipt (created_at, queued_at, queue), counts the started rows as
        // attempts (nothing incremented attempt before), and keeps a manual-retry mark and
        // audit log that findOneBy() may have written to any of the rows.
        $this->addSql(<<<'SQL'
            WITH jobs AS (
                SELECT job_id,
                       (array_agg(id ORDER BY created_at DESC, id DESC))[1] AS kept_id,
                       (array_agg(created_at ORDER BY created_at, id))[1] AS created_at,
                       (array_agg(queued_at ORDER BY created_at, id))[1] AS queued_at,
                       (array_agg(queue ORDER BY created_at, id))[1] AS queue,
                       count(*) FILTER (WHERE started_at IS NOT NULL) AS attempts,
                       bool_or(retried) AS retried,
                       (array_agg(audit_log ORDER BY updated_at DESC) FILTER (WHERE audit_log IS NOT NULL))[1] AS audit_log
                FROM job_monitors
                GROUP BY job_id
            ), merged AS (
                DELETE FROM job_monitors AS monitor
                USING jobs
                WHERE monitor.job_id = jobs.job_id AND monitor.id <> jobs.kept_id
            )
            UPDATE job_monitors AS monitor
            SET created_at = jobs.created_at, queued_at = jobs.queued_at, queue = jobs.queue,
                attempt = jobs.attempts, retried = jobs.retried, audit_log = jobs.audit_log
            FROM jobs
            WHERE monitor.id = jobs.kept_id
            SQL);

        // A retry that restarted a failed row left its old finish time behind; the job has not
        // finished since. A finished row's finish time follows its start unless the clock went
        // back, so clamp those to the start.
        $this->addSql(<<<'SQL'
            UPDATE job_monitors
            SET finished_at = CASE WHEN status IN ('queued', 'running') THEN NULL ELSE started_at END
            WHERE finished_at < started_at
            SQL);

        // The unique constraint's index replaces the plain job_id index.
        $this->addSql('DROP INDEX idx_job_monitors_job_id');
        $this->addSql('ALTER TABLE job_monitors ADD CONSTRAINT uniq_job_monitors_job_id UNIQUE (job_id)');
        $this->addSql('ALTER TABLE job_monitors ADD CONSTRAINT chk_job_monitors_finished_at CHECK (finished_at >= started_at)');
    }

    public function down(Schema $schema): void
    {
        // Merged duplicate rows are not restored: they described the same job.
        $this->addSql('ALTER TABLE job_monitors DROP CONSTRAINT chk_job_monitors_finished_at');
        $this->addSql('ALTER TABLE job_monitors DROP CONSTRAINT uniq_job_monitors_job_id');
        $this->addSql('CREATE INDEX idx_job_monitors_job_id ON job_monitors (job_id)');
    }
}
