<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store member file resume positions with monotonic report fences and viewing hints.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql(<<<'SQL'
            CREATE TABLE resume_positions (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                video_id UUID NOT NULL,
                position_seconds DOUBLE PRECISION DEFAULT NULL,
                reported_at TIMESTAMPTZ NOT NULL,
                last_played_at TIMESTAMPTZ DEFAULT NULL,
                CONSTRAINT resume_positions_pkey PRIMARY KEY (id),
                CONSTRAINT uniq_resume_positions_user_id_video_id UNIQUE (user_id, video_id),
                CONSTRAINT chk_resume_positions_position CHECK (position_seconds >= 0 AND position_seconds < 'Infinity'::double precision),
                CONSTRAINT fk_resume_positions_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_resume_positions_video_id FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_resume_positions_video_id ON resume_positions (video_id)');
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON resume_positions REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,video_id,position_seconds,reported_at,last_played_at}', '{activity,catalog}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON resume_positions REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,video_id,position_seconds,reported_at,last_played_at}', '{activity,catalog}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON resume_positions REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,video_id,position_seconds,reported_at,last_played_at}', '{activity,catalog}', '{user}')");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Member resume positions cannot be restored after rollback.');
    }
}
