<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** DBAL-owned boot tombstones; deliberately independent of inventory schema creation order. */
final class Version20261003020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist irreversible registered-deployment retirement intent and containment completion.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE worker_deployment_retirements (
                namespace TEXT NOT NULL CHECK (namespace ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$'),
                boot_id TEXT NOT NULL CHECK (boot_id ~ '^[0-9a-f]{32}$'),
                daemon_id TEXT NOT NULL CHECK (daemon_id ~ '^[A-Za-z0-9_.:-]{1,128}$'),
                container_id TEXT NOT NULL CHECK (container_id ~ '^[0-9a-f]{64}$'),
                intended_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
                completed_at TIMESTAMPTZ DEFAULT NULL,
                PRIMARY KEY (namespace, boot_id),
                UNIQUE (daemon_id, container_id)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Dropping retirement tombstones would permit admission of removed deployments.');
    }
}
