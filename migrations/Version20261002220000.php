<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Fresh-install DBAL inventory, independent of the later lease epoch; no production deployments. */
final class Version20261002220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reserve one deployment creation attempt and record immutable bindings before starting workers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE worker_deployment_creations (
                namespace TEXT NOT NULL CHECK (namespace ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$'),
                boot_id TEXT NOT NULL CHECK (boot_id ~ '^[0-9a-f]{32}$'),
                daemon_id TEXT NOT NULL CHECK (daemon_id ~ '^[A-Za-z0-9_.:-]{1,128}$'),
                recipe_hash TEXT NOT NULL CHECK (recipe_hash ~ '^[0-9a-f]{64}$'),
                created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
                PRIMARY KEY (namespace, boot_id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE worker_deployment_containers (
                namespace TEXT NOT NULL CHECK (namespace ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$'),
                boot_id TEXT NOT NULL CHECK (boot_id ~ '^[0-9a-f]{32}$'),
                daemon_id TEXT NOT NULL CHECK (daemon_id ~ '^[A-Za-z0-9_.:-]{1,128}$'),
                container_id TEXT NOT NULL CHECK (container_id ~ '^[0-9a-f]{64}$'),
                created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
                start_claimed_at TIMESTAMPTZ DEFAULT NULL,
                PRIMARY KEY (namespace, boot_id),
                UNIQUE (daemon_id, container_id)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Dropping deployment inventory would permit recreating or rebinding previously admitted boots.');
    }
}
