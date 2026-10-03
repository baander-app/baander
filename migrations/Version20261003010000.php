<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** DBAL-owned permanent admission; expiry or a missing return never permits another attempt. */
final class Version20261003010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Consume scheduler occurrence execution admission once and retain exact attempt return markers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE scheduler_occurrence_executions (
                occurrence_id UUID PRIMARY KEY REFERENCES scheduler_occurrences(id) ON DELETE RESTRICT,
                attempt_id UUID NOT NULL UNIQUE,
                deployment_namespace TEXT NOT NULL CHECK (deployment_namespace ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$'),
                deployment_boot_id TEXT NOT NULL CHECK (deployment_boot_id ~ '^[0-9a-f]{32}$'),
                deployment_epoch BIGINT NOT NULL CHECK (deployment_epoch > 0),
                started_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
                returned_at TIMESTAMPTZ DEFAULT NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Dropping permanent execution admissions would authorize duplicate scheduler effects.');
    }
}
