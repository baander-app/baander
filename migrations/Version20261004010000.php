<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Require encrypted original webhook signing secrets and remove obsolete hashed-key signing.';
    }

    public function up(Schema $schema): void
    {
        // Serialize the precondition with concurrent configuration writes. The
        // transactional migration releases this lock on success or failure.
        $this->addSql('LOCK TABLE webhooks IN ACCESS EXCLUSIVE MODE');
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM webhooks WHERE encrypted_secret IS NULL OR encrypted_secret = '') THEN
                    RAISE EXCEPTION 'Webhook secrets require rotation before upgrade; use the previous application rotate-secret endpoint and configure each receiver. No webhook data was changed.';
                END IF;
            END
            $$
            SQL);
        $this->addSql('ALTER TABLE webhooks ALTER encrypted_secret SET NOT NULL, DROP secret_hash, DROP signing_version');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Obsolete hashed-key signing cannot be restored. Restore a matching application/database backup instead.');
    }
}
