<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist video execution intent and independent audio rendition ownership.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql(<<<'SQL'
CREATE TABLE transcode_runtime_state (
    job_id UUID NOT NULL PRIMARY KEY,
    session_id UUID NOT NULL,
    request_id TEXT NOT NULL,
    last_control_request_id TEXT,
    desired JSONB NOT NULL,
    desired_revision INT NOT NULL,
    observed_revision INT NOT NULL,
    generation INT NOT NULL,
    outcome TEXT NOT NULL,
    output_directory TEXT,
    error TEXT,
    admission_deadline TIMESTAMPTZ NOT NULL,
    worker_namespace TEXT,
    worker_boot_id TEXT,
    worker_id TEXT,
    worker_launch_generation INT,
    worker_lease_epoch INT,
    claim_token TEXT,
    lease_expires_at TIMESTAMPTZ,
    updated_at TIMESTAMPTZ NOT NULL,
    CONSTRAINT fk_transcode_runtime_job FOREIGN KEY (job_id) REFERENCES transcode_jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_transcode_runtime_session FOREIGN KEY (session_id) REFERENCES transcode_sessions(id) ON DELETE CASCADE,
    CONSTRAINT chk_transcode_runtime_revision CHECK (desired_revision > 0 AND observed_revision >= 0 AND observed_revision <= desired_revision AND generation > 0),
    CONSTRAINT chk_transcode_runtime_outcome CHECK (outcome IN ('pending', 'accepted', 'ready', 'complete', 'failed', 'cancelled', 'refused')),
    CONSTRAINT chk_transcode_runtime_owner CHECK ((claim_token IS NULL AND lease_expires_at IS NULL AND worker_namespace IS NULL AND worker_boot_id IS NULL AND worker_id IS NULL AND worker_launch_generation IS NULL AND worker_lease_epoch IS NULL) OR (claim_token IS NOT NULL AND lease_expires_at IS NOT NULL AND worker_namespace IS NOT NULL AND worker_boot_id IS NOT NULL AND worker_id IS NOT NULL AND worker_launch_generation IS NOT NULL AND worker_launch_generation > 0 AND worker_lease_epoch IS NOT NULL AND worker_lease_epoch > 0))
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE audio_rendition_runtime (
    rendition_key TEXT NOT NULL PRIMARY KEY,
    track_key TEXT NOT NULL,
    source_signature TEXT NOT NULL,
    source_path TEXT NOT NULL,
    format TEXT NOT NULL,
    bitrate INT NOT NULL,
    output_directory TEXT NOT NULL,
    state TEXT NOT NULL,
    generation INT NOT NULL,
    partial_path TEXT,
    final_path TEXT,
    error TEXT,
    worker_namespace TEXT,
    worker_boot_id TEXT,
    worker_id TEXT,
    worker_launch_generation INT,
    worker_lease_epoch INT,
    claim_token TEXT,
    lease_expires_at TIMESTAMPTZ,
    updated_at TIMESTAMPTZ NOT NULL,
    CONSTRAINT uniq_audio_rendition_canonical UNIQUE (track_key, source_signature, format, bitrate),
    CONSTRAINT chk_audio_rendition_state CHECK (state IN ('pending', 'running', 'complete', 'failed') AND generation >= 0 AND bitrate > 0),
    CONSTRAINT chk_audio_runtime_owner CHECK ((claim_token IS NULL AND lease_expires_at IS NULL AND worker_namespace IS NULL AND worker_boot_id IS NULL AND worker_id IS NULL AND worker_launch_generation IS NULL AND worker_lease_epoch IS NULL) OR (claim_token IS NOT NULL AND lease_expires_at IS NOT NULL AND worker_namespace IS NOT NULL AND worker_boot_id IS NOT NULL AND worker_id IS NOT NULL AND worker_launch_generation IS NOT NULL AND worker_launch_generation > 0 AND worker_lease_epoch IS NOT NULL AND worker_lease_epoch > 0))
)
SQL);
        $this->addSql('CREATE INDEX idx_transcode_runtime_session ON transcode_runtime_state (session_id)');
        $this->addSql("CREATE INDEX idx_transcode_runtime_pending ON transcode_runtime_state (updated_at, job_id) WHERE outcome IN ('pending', 'accepted', 'ready')");
        $this->addSql("CREATE INDEX idx_audio_rendition_runtime_pending ON audio_rendition_runtime (updated_at, rendition_key) WHERE state IN ('pending', 'running')");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Media runtime admission cannot be downgraded: ownership history cannot be reconstructed after deletion.');
    }
}
