<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** DBAL-owned immutable scheduler intents, independent of mutable schedule lifetime. */
final class Version20261002230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retain one immutable scheduler intent per job/minute without dispatching it.';
    }

    public function up(Schema $schema): void
    {
        // JSON deliberately preserves canonical numeric lexemes: JSONB can turn
        // a PHP integral exponent float into an integer on hydration. No JSON queries/indexes.
        // No FK: deletion of a schedule must not erase retained intent/dedup history.
        $this->addSql(<<<'SQL'
            CREATE TABLE scheduler_occurrences (
                id UUID PRIMARY KEY,
                job_id UUID NOT NULL,
                scheduled_for TIMESTAMPTZ NOT NULL CHECK (scheduled_for = date_trunc('minute', scheduled_for, 'UTC')),
                job_type TEXT NOT NULL CHECK (job_type IN ('messenger', 'console')),
                command TEXT NOT NULL CHECK (octet_length(command) BETWEEN 1 AND 512),
                parameters JSON NOT NULL CHECK (json_typeof(parameters) IN ('object', 'array') AND octet_length(parameters::text) <= 16384),
                dispatch_after TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
                dispatch_token UUID DEFAULT NULL,
                dispatched_at TIMESTAMPTZ DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
                UNIQUE (job_id, scheduled_for)
            )
            SQL);
        $this->addSql('CREATE INDEX scheduler_occurrences_pending_dispatch_idx ON scheduler_occurrences (dispatch_after, scheduled_for, id) WHERE dispatched_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Dropping retained scheduler occurrences would erase immutable intent and duplicate prevention history.');
    }
}
