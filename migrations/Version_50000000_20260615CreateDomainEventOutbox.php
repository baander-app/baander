<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the domain_event_outbox table backing OutboxRepository.
 *
 * The transactional-outbox pattern (OutboxSubscriber + OutboxRepository +
 * RelayOutboxHandler) has been wired since the initial schema, but the table
 * itself was never created — it is excluded from Doctrine's schema tooling via
 * the schema_filter in config/packages/doctrine.yaml, so diffs never emitted it.
 * Without this table every command that produces a domain event (including
 * dev user creation) fails with SQLSTATE[42P01] relation "domain_event_outbox"
 * does not exist.
 *
 * This migration uses raw addSql() which is unaffected by schema_filter, and
 * the filter prevents future diffs from trying to drop or alter the table.
 */
final class Version520260615CreateDomainEventOutbox extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create domain_event_outbox table for the transactional outbox pattern';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS domain_event_outbox (
            id BIGSERIAL PRIMARY KEY,
            event_class TEXT NOT NULL,
            event_name TEXT NOT NULL,
            payload JSONB NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            relayed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL
        )');

        // Serves OutboxRepository::fetchPending(): relayed_at IS NULL ORDER BY created_at
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_domain_event_outbox_pending ON domain_event_outbox (created_at) WHERE relayed_at IS NULL');;
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE domain_event_outbox');
    }
}
