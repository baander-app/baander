<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107120000 extends AbstractMigration
{
    private const string RENDITION_COLUMNS = 'rendition_key,track_key,source_signature,source_path,format,bitrate,output_directory,state,generation,partial_path,final_path,error,mode,stream_index,output_container,source_hash,failed_aac_implementation';

    public function getDescription(): string
    {
        return 'Record the AAC implementation a failed audio rendition encode used.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql('ALTER TABLE audio_rendition_runtime ADD failed_aac_implementation TEXT DEFAULT NULL');
        $this->addSql(<<<'SQL'
ALTER TABLE audio_rendition_runtime ADD CONSTRAINT chk_audio_rendition_runtime_failed_aac_implementation CHECK (
    failed_aac_implementation IS NULL
    OR (state = 'failed' AND failed_aac_implementation IN ('baac', 'fdk_aac', 'ffmpeg'))
)
SQL);
        $arguments = "'{" . self::RENDITION_COLUMNS . "}', '{admin-media}', '{admin}'";
        $this->addSql("CREATE OR REPLACE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON audio_rendition_runtime FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
        $this->addSql("CREATE OR REPLACE TRIGGER query_capture_insert AFTER INSERT ON audio_rendition_runtime FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing the recorded implementation would leave failed renditions that switching the AAC implementation can no longer reopen.');
    }
}
