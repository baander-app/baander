<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist session-scoped control receipts and align media runtime schema names.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql(<<<'SQL'
CREATE TABLE transcode_control_receipts (
    session_id UUID NOT NULL,
    request_id TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY (session_id, request_id),
    CONSTRAINT fk_transcode_control_receipts_session_id FOREIGN KEY (session_id) REFERENCES transcode_sessions (id) ON DELETE CASCADE
)
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO transcode_control_receipts (session_id, request_id, created_at)
SELECT session_id, last_control_request_id, clock_timestamp()
FROM transcode_runtime_state
WHERE last_control_request_id IS NOT NULL
SQL);
        $this->addSql('ALTER TABLE transcode_runtime_state DROP COLUMN last_control_request_id');
        $this->addSql('ALTER TABLE transcode_runtime_state RENAME CONSTRAINT fk_transcode_runtime_job TO fk_transcode_runtime_state_job_id');
        $this->addSql('ALTER TABLE transcode_runtime_state RENAME CONSTRAINT fk_transcode_runtime_session TO fk_transcode_runtime_state_session_id');
        $this->addSql('ALTER INDEX idx_transcode_runtime_pending RENAME TO idx_transcode_runtime_state_pending');
        $this->addSql('ALTER INDEX idx_transcode_runtime_session RENAME TO idx_transcode_runtime_state_session_id');
        $this->addSql('ALTER TABLE audio_rendition_runtime RENAME CONSTRAINT uniq_audio_rendition_canonical TO uniq_audio_rendition_runtime_canonical');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Durable media control receipts cannot be downgraded without losing replay protection.');
    }
}
