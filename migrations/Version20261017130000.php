<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guard playlist aggregate writes with a non-negative persistence version.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playlists ADD persistence_version INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE playlists ADD CONSTRAINT chk_playlists_persistence_version CHECK (persistence_version >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playlists DROP CONSTRAINT chk_playlists_persistence_version');
        $this->addSql('ALTER TABLE playlists DROP persistence_version');
    }
}
