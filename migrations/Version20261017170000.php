<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope new video jobs to their playback owner while preserving unowned history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql('ALTER TABLE transcode_jobs ADD owner_user_id UUID');
        $this->addSql(<<<'SQL'
UPDATE transcode_jobs j
SET owner_user_id = s.user_id
FROM transcode_runtime_state r
JOIN transcode_sessions s ON s.id = r.session_id
WHERE r.job_id = j.id AND s.job_id = j.id
SQL);
        $this->addSql(<<<'SQL'
UPDATE transcode_jobs j
SET owner_user_id = sole.user_id
FROM (
    SELECT DISTINCT s.job_id, s.user_id
    FROM transcode_sessions s
    WHERE NOT EXISTS (
        SELECT 1 FROM transcode_sessions other
        WHERE other.job_id = s.job_id AND other.user_id <> s.user_id
    )
) sole
WHERE j.owner_user_id IS NULL AND sole.job_id = j.id
SQL);
        $this->addSql('ALTER TABLE transcode_jobs ADD CONSTRAINT fk_transcode_jobs_owner_user_id FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('DROP INDEX uniq_transcode_jobs_video_id_quality_tier_name');
        $this->addSql('CREATE UNIQUE INDEX uniq_transcode_jobs_owner_user_id_video_id_quality_tier_name ON transcode_jobs (owner_user_id, video_id, quality_tier_name)');
        $this->addSql('CREATE INDEX idx_transcode_jobs_video_id ON transcode_jobs (video_id)');
        $this->addSql(<<<'SQL'
UPDATE transcode_runtime_state
SET generation = generation + 1,
    observed_revision = 0,
    outcome = 'accepted',
    output_directory = NULL,
    worker_namespace = NULL,
    worker_boot_id = NULL,
    worker_id = NULL,
    worker_launch_generation = NULL,
    worker_lease_epoch = NULL,
    claim_token = NULL,
    lease_expires_at = NULL,
    updated_at = clock_timestamp()
WHERE outcome IN ('accepted', 'ready', 'complete')
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Owner-scoped video jobs cannot be downgraded without discarding independent playback histories.');
    }
}
