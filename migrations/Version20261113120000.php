<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the stored AAC implementation value baac to bac.';
    }

    public function up(Schema $schema): void
    {
        $this->renameValue('baac', 'bac');
    }

    public function down(Schema $schema): void
    {
        $this->renameValue('bac', 'baac');
    }

    /**
     * Rewrite one AAC implementation value wherever it is stored: the setting, the implementation
     * a failed audio rendition recorded, and the implementation in a media runtime row's desired
     * state. Every row that holds the value is rewritten, whatever its state or outcome, because
     * the runtime readers refuse an unknown value and fail the whole batch they read. A failed
     * rendition keeps its value under the new name, so switching the implementation still reopens
     * it. Rows that hold another value, or none, are not written, and updated_at keeps its value
     * because the worker queues are ordered by it.
     */
    private function renameValue(string $from, string $to): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql('ALTER TABLE audio_rendition_runtime DROP CONSTRAINT chk_audio_rendition_runtime_failed_aac_implementation');
        $this->addSql(sprintf(<<<'SQL'
UPDATE system_settings SET value = '"%2$s"'::jsonb
WHERE key = 'transcode.aac_implementation' AND value = '"%1$s"'::jsonb
SQL, $from, $to));
        $this->addSql(sprintf(<<<'SQL'
UPDATE audio_rendition_runtime SET failed_aac_implementation = '%2$s'
WHERE failed_aac_implementation = '%1$s'
SQL, $from, $to));
        $this->addSql(sprintf(<<<'SQL'
UPDATE transcode_runtime_state SET desired = jsonb_set(desired, '{aacImplementation}', '"%2$s"'::jsonb)
WHERE desired->'aacImplementation' = '"%1$s"'::jsonb
SQL, $from, $to));
        $this->addSql(sprintf(<<<'SQL'
ALTER TABLE audio_rendition_runtime ADD CONSTRAINT chk_audio_rendition_runtime_failed_aac_implementation CHECK (
    failed_aac_implementation IS NULL
    OR (state = 'failed' AND failed_aac_implementation IN ('%s', 'fdk_aac', 'ffmpeg'))
)
SQL, $to));
    }
}
