<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist owner-bound stop-only credentials for eligible browser playback recovery.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql(<<<'SQL'
            ALTER TABLE playback_destination_incarnations
                ADD COLUMN tab_id UUID DEFAULT NULL,
                ADD COLUMN local_recovery_eligible BOOLEAN NOT NULL DEFAULT FALSE,
                ADD COLUMN recovery_credential_hash TEXT DEFAULT NULL,
                ADD COLUMN recovery_expires_at TIMESTAMPTZ DEFAULT NULL,
                ADD COLUMN recovery_revoked_at TIMESTAMPTZ DEFAULT NULL,
                ADD COLUMN recovery_result JSONB DEFAULT NULL,
                ADD CONSTRAINT chk_playback_destination_incarnations_local_recovery_authority CHECK (
                    (NOT local_recovery_eligible AND tab_id IS NULL AND recovery_credential_hash IS NULL
                        AND recovery_expires_at IS NULL AND recovery_revoked_at IS NULL AND recovery_result IS NULL)
                    OR (local_recovery_eligible AND tab_id IS NOT NULL AND recovery_credential_hash IS NOT NULL
                        AND recovery_credential_hash ~ '^[0-9a-f]{64}$' AND recovery_expires_at IS NOT NULL)
                )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing durable recovery authority would strand uncertain browser playback.');
    }
}
