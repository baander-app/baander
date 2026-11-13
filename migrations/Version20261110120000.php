<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record when a playback command was first shown to its endpoint and where a command without effect left the session.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql('ALTER TABLE playback_commands ADD disclosed_at timestamptz DEFAULT NULL');
        $this->addSql('ALTER TABLE playback_commands ADD settled_revision integer DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE playback_commands ADD CONSTRAINT chk_playback_commands_settled_revision CHECK (
                (settled_revision IS NULL AND outcome IS DISTINCT FROM 'never_effective')
                OR (settled_revision IS NOT NULL AND settled_revision >= revision AND outcome IS NOT DISTINCT FROM 'never_effective')
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing disclosure records would let a command its endpoint was shown pass as one it never received.');
    }
}
