<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add versioned, encrypted webhook secrets; preserve legacy hashed-key signatures.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webhooks ADD signing_version SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE webhooks ADD encrypted_secret TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Downgrading would discard encrypted secrets and change version 2 signatures. Restore a matching pre-migration backup instead.');
    }
}
