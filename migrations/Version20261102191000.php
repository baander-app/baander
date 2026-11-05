<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102191000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index playback owner-scoped foreign keys for parent updates and deletion.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql('CREATE INDEX idx_playback_commands_session_id_user_id ON playback_commands (session_id, user_id)');
        $this->addSql('CREATE INDEX idx_playback_commands_incarnation_id_user_id ON playback_commands (incarnation_id, user_id)');
        $this->addSql('CREATE INDEX idx_playback_destination_incarnations_destination_id_user_id ON playback_destination_incarnations (destination_id, user_id)');
        $this->addSql('CREATE INDEX idx_playback_transfers_session_id_user_id ON playback_transfers (session_id, user_id)');
        $this->addSql('CREATE INDEX idx_playback_transfers_source_incarnation_id_user_id ON playback_transfers (source_incarnation_id, user_id)');
        $this->addSql('CREATE INDEX idx_playback_transfers_target_incarnation_id_user_id ON playback_transfers (target_incarnation_id, user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_playback_transfers_target_incarnation_id_user_id');
        $this->addSql('DROP INDEX idx_playback_transfers_source_incarnation_id_user_id');
        $this->addSql('DROP INDEX idx_playback_transfers_session_id_user_id');
        $this->addSql('DROP INDEX idx_playback_destination_incarnations_destination_id_user_id');
        $this->addSql('DROP INDEX idx_playback_commands_incarnation_id_user_id');
        $this->addSql('DROP INDEX idx_playback_commands_session_id_user_id');
    }
}
