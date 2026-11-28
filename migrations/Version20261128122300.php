<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128122300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store member watched marks and durable placement move receipts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql(<<<'SQL'
            CREATE TABLE watched_marks (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                episode_id UUID DEFAULT NULL,
                movie_id UUID DEFAULT NULL,
                watched_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT watched_marks_pkey PRIMARY KEY (id),
                CONSTRAINT uniq_watched_marks_user_id_episode_id UNIQUE (user_id, episode_id),
                CONSTRAINT uniq_watched_marks_user_id_movie_id UNIQUE (user_id, movie_id),
                CONSTRAINT chk_watched_marks_single_target CHECK ((episode_id IS NOT NULL) <> (movie_id IS NOT NULL)),
                CONSTRAINT fk_watched_marks_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_watched_marks_episode_id FOREIGN KEY (episode_id) REFERENCES tv_episodes (id) ON DELETE CASCADE,
                CONSTRAINT fk_watched_marks_movie_id FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_watched_marks_episode_id ON watched_marks (episode_id)');
        $this->addSql('CREATE INDEX idx_watched_marks_movie_id ON watched_marks (movie_id)');
        $this->addSql('CREATE TABLE watched_mark_moves (operation_id UUID NOT NULL, CONSTRAINT watched_mark_moves_pkey PRIMARY KEY (operation_id))');
        $this->addSql("CREATE TRIGGER query_capture_insert AFTER INSERT ON watched_marks REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,episode_id,movie_id,watched_at}', '{activity,catalog}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_update AFTER UPDATE ON watched_marks REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,episode_id,movie_id,watched_at}', '{activity,catalog}', '{user}')");
        $this->addSql("CREATE TRIGGER query_capture_delete AFTER DELETE ON watched_marks REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement('{id,user_id,episode_id,movie_id,watched_at}', '{activity,catalog}', '{user}')");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing watched state would discard member history and move receipts.');
    }
}
