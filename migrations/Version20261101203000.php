<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow retained queue edits before a playback destination is active.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql(<<<'SQL'
            ALTER TABLE playback_commands DROP CONSTRAINT chk_playback_commands_authority,
            ADD CONSTRAINT chk_playback_commands_authority CHECK (
                (incarnation_id IS NULL AND epoch IS NULL AND outcome IS NOT NULL AND outcome='retained'
                    AND acknowledged_at IS NOT NULL AND type IN ('load','play','pause','seek','stop','queue'))
                OR (incarnation_id IS NOT NULL AND epoch IS NOT NULL AND epoch>0)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Retained queue history cannot be represented by the previous command constraint.');
    }
}
