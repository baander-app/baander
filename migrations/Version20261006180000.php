<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index party_sessions.host_user_id for the users ON DELETE CASCADE lookup.';
    }

    public function up(Schema $schema): void
    {
        // Without it, every user deletion scans party_sessions to apply fk_party_sessions_host_user_id.
        $this->addSql('CREATE INDEX idx_party_sessions_host_user_id ON party_sessions (host_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_party_sessions_host_user_id');
    }
}
