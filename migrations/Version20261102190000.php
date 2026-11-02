<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist source stream selections and isolate copy presentation identities.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql('ALTER TABLE songs ADD container_format TEXT DEFAULT NULL, ADD stream_index INT DEFAULT NULL');
        $this->addSql('ALTER TABLE songs ADD CONSTRAINT chk_songs_stream_index CHECK (stream_index IS NULL OR stream_index >= 0)');
        $this->addSql("ALTER TABLE transcode_jobs ADD presentation_selection JSONB DEFAULT NULL, ADD presentation_key TEXT DEFAULT 'legacy' NOT NULL");
        $this->addSql('ALTER TABLE transcode_jobs ADD request_id TEXT');
        $this->addSql('UPDATE transcode_jobs SET request_id = id::text');
        $this->addSql('ALTER TABLE transcode_jobs ALTER request_id SET NOT NULL');
        $this->addSql('DROP INDEX uniq_transcode_jobs_owner_user_id_video_id_quality_tier_name');
        $this->addSql('CREATE UNIQUE INDEX uniq_transcode_jobs_owner_user_id_video_id_quality_tier_name ON transcode_jobs (owner_user_id, video_id, quality_tier_name, presentation_key, request_id)');
        $this->addSql("ALTER TABLE audio_rendition_runtime ADD mode TEXT DEFAULT 'encode' NOT NULL, ADD stream_index INT DEFAULT NULL, ADD output_container TEXT DEFAULT NULL, ADD source_hash TEXT DEFAULT NULL, ALTER bitrate DROP NOT NULL");
        $this->addSql('ALTER TABLE audio_rendition_runtime DROP CONSTRAINT uniq_audio_rendition_runtime_canonical, DROP CONSTRAINT chk_audio_rendition_state');
        $this->addSql("ALTER TABLE audio_rendition_runtime ADD CONSTRAINT chk_audio_rendition_state CHECK (state IN ('pending', 'running', 'complete', 'failed') AND generation >= 0)");
        $this->addSql(<<<'SQL'
ALTER TABLE audio_rendition_runtime ADD CONSTRAINT chk_audio_rendition_operation CHECK (
    (mode = 'encode' AND bitrate IS NOT NULL AND bitrate > 0)
    OR (mode = 'copy' AND bitrate IS NULL AND stream_index IS NOT NULL AND stream_index >= 0
        AND output_container IS NOT NULL AND output_container = 'mp4' AND source_hash IS NOT NULL AND source_hash <> '')
)
SQL);
        $this->addSql('ALTER TABLE audio_rendition_runtime ADD CONSTRAINT chk_audio_rendition_stream_index CHECK (stream_index IS NULL OR stream_index >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Copy presentations cannot be represented by the earlier encoding-only identities.');
    }
}
