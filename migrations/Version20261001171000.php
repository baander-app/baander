<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001171000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist outbox claim leases across transaction boundaries.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE domain_event_outbox ADD lease_token TEXT DEFAULT NULL, ADD lease_until TIMESTAMPTZ DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_outbox_claim_expiry ON domain_event_outbox (lease_until) WHERE relayed_at IS NULL AND dead_lettered_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_outbox_claim_expiry');
        $this->addSql('ALTER TABLE domain_event_outbox DROP lease_token, DROP lease_until');
    }
}
