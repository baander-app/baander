<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add measured_loudness, audio_track_languages, and audio_segment_map columns to transcode_jobs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transcode_jobs ADD COLUMN measured_loudness JSONB DEFAULT \'{}\' NOT NULL');
        $this->addSql('ALTER TABLE transcode_jobs ADD COLUMN audio_track_languages JSONB DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE transcode_jobs ADD COLUMN audio_segment_map JSONB DEFAULT \'{}\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transcode_jobs DROP COLUMN measured_loudness');
        $this->addSql('ALTER TABLE transcode_jobs DROP COLUMN audio_track_languages');
        $this->addSql('ALTER TABLE transcode_jobs DROP COLUMN audio_segment_map');
    }
}
