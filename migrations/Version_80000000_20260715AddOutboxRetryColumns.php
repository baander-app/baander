<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds retry and dead-letter tracking to the transactional outbox table.
 *
 * The domain_event_outbox table is excluded from Doctrine's schema tooling via
 * the schema_filter in config/packages/doctrine.yaml, so this migration uses
 * raw addSql() to add the columns and supporting index.
 */
final class Version_80000000_20260715AddOutboxRetryColumns extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add attempts, next_attempt_at and dead_lettered_at to domain_event_outbox';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE domain_event_outbox
            ADD COLUMN IF NOT EXISTS attempts INT NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS next_attempt_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS dead_lettered_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL
        ');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_domain_event_outbox_retry
            ON domain_event_outbox (next_attempt_at)
            WHERE relayed_at IS NULL AND dead_lettered_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_domain_event_outbox_retry');
        $this->addSql('ALTER TABLE domain_event_outbox
            DROP COLUMN IF EXISTS attempts,
            DROP COLUMN IF EXISTS next_attempt_at,
            DROP COLUMN IF EXISTS dead_lettered_at
        ');
    }
}
