<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105120000 extends AbstractMigration
{
    private const string SESSION_COLUMNS = 'id,public_id,user_id,active_device_id,queue,current_track_index,playback_state,created_at';
    private const string SESSION_PLAYBACK_COLUMNS = 'revision,media,desired_state,observed_state,selected_destination_id,active_incarnation_id';

    public function getDescription(): string
    {
        return 'Capture playback destination and state column changes of listening sessions for live queries.';
    }

    public function up(Schema $schema): void
    {
        $this->captureTriggers(self::SESSION_COLUMNS . ',' . self::SESSION_PLAYBACK_COLUMNS);
    }

    public function down(Schema $schema): void
    {
        $this->captureTriggers(self::SESSION_COLUMNS);
    }

    private function captureTriggers(string $columns): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $arguments = "'{" . $columns . "}', '{sessions}', '{user}'";
        $this->addSql("CREATE OR REPLACE TRIGGER query_capture_insert AFTER INSERT ON listening_sessions REFERENCING NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement({$arguments})");
        $this->addSql("CREATE OR REPLACE TRIGGER query_capture_update AFTER UPDATE ON listening_sessions REFERENCING OLD TABLE AS query_old_rows NEW TABLE AS query_new_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement({$arguments})");
        $this->addSql("CREATE OR REPLACE TRIGGER query_capture_delete AFTER DELETE ON listening_sessions REFERENCING OLD TABLE AS query_old_rows FOR EACH STATEMENT EXECUTE FUNCTION query_live_capture_users_statement({$arguments})");
    }
}
