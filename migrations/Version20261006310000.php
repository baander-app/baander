<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006310000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Constrain party_sessions.video_id and the now optional party_sessions.transcode_job_id with foreign keys.';
    }

    public function up(Schema $schema): void
    {
        // A party whose video no longer exists cannot be played. Its members and events are
        // deleted through fk_party_members_session_id and fk_party_events_session_id.
        $this->addSql('DELETE FROM party_sessions s WHERE NOT EXISTS (SELECT 1 FROM videos v WHERE v.id = s.video_id)');

        // Orphaned-job cleanup may delete a job a running party names; the party then continues without one.
        $this->addSql('ALTER TABLE party_sessions ALTER COLUMN transcode_job_id DROP NOT NULL');
        // Clear references to jobs that no longer exist or that encode another video.
        $this->addSql('UPDATE party_sessions s SET transcode_job_id = NULL WHERE s.transcode_job_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM transcode_jobs j WHERE j.id = s.transcode_job_id AND j.video_id = s.video_id)');

        $this->addSql('ALTER TABLE party_sessions ADD CONSTRAINT fk_party_sessions_video_id FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE party_sessions ADD CONSTRAINT fk_party_sessions_transcode_job_id FOREIGN KEY (transcode_job_id) REFERENCES transcode_jobs (id) ON DELETE SET NULL');
        // idx_party_sessions_video_id (Version001_InitialSchema) already serves the video cascade.
        $this->addSql('CREATE INDEX idx_party_sessions_transcode_job_id ON party_sessions (transcode_job_id)');
    }

    public function down(Schema $schema): void
    {
        // Restoring NOT NULL would require deleting parties whose job was cleared; refuse instead.
        $this->abortIf(
            (bool) $this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM party_sessions WHERE transcode_job_id IS NULL)'),
            'Some party sessions have no transcode job; transcode_job_id cannot be made NOT NULL again.',
        );

        // The parties deleted and references cleared by up() are not restored.
        $this->addSql('DROP INDEX idx_party_sessions_transcode_job_id');
        $this->addSql('ALTER TABLE party_sessions DROP CONSTRAINT fk_party_sessions_transcode_job_id');
        $this->addSql('ALTER TABLE party_sessions DROP CONSTRAINT fk_party_sessions_video_id');
        $this->addSql('ALTER TABLE party_sessions ALTER COLUMN transcode_job_id SET NOT NULL');
    }
}
