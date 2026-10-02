<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist outbox consumer receipts and notification channel delivery intents.';
    }

    public function up(Schema $schema): void
    {
        // Older events also ran live listeners; their delivery status is uncertain.
        // Preserve them for reconciliation instead of automatically duplicating effects.
        $this->addSql('ALTER TABLE domain_event_outbox ADD legacy_review_required BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('UPDATE domain_event_outbox SET legacy_review_required = TRUE, dead_lettered_at = NOW(), lease_token = NULL, lease_until = NULL WHERE relayed_at IS NULL AND dead_lettered_at IS NULL');
        $this->addSql(<<<'SQL'
            CREATE TABLE domain_event_outbox_receipt (
                outbox_id BIGINT NOT NULL REFERENCES domain_event_outbox(id) ON DELETE CASCADE,
                consumer VARCHAR(128) NOT NULL,
                processed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (outbox_id, consumer)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE domain_event_outbox_delivery (
                id BIGSERIAL PRIMARY KEY,
                channel TEXT NOT NULL CHECK (channel IN ('email', 'push', 'webhook')),
                notification_id VARCHAR(64) NOT NULL,
                payload TEXT NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                next_attempt_at TIMESTAMPTZ DEFAULT NULL,
                dead_lettered_at TIMESTAMPTZ DEFAULT NULL,
                relayed_at TIMESTAMPTZ DEFAULT NULL,
                lease_token TEXT DEFAULT NULL,
                lease_until TIMESTAMPTZ DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (channel, notification_id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_outbox_delivery_pending ON domain_event_outbox_delivery (next_attempt_at, created_at, id, lease_until) WHERE relayed_at IS NULL AND dead_lettered_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE domain_event_outbox_delivery');
        $this->addSql('DROP TABLE domain_event_outbox_receipt');
        $this->addSql('ALTER TABLE domain_event_outbox DROP legacy_review_required');
    }
}
