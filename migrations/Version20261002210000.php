<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** DBAL worker coordination state; intentionally not an ORM entity. */
final class Version20261002210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist deployment ownership epochs requiring explicit containment acknowledgment before takeover.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE worker_deployment_leases (
                namespace TEXT PRIMARY KEY CHECK (namespace ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$'),
                owner_boot_id TEXT NOT NULL CHECK (owner_boot_id ~ '^[0-9a-f]{32}$'),
                epoch BIGINT NOT NULL CHECK (epoch > 0),
                state TEXT NOT NULL CHECK (state IN ('active', 'available')),
                expires_at TIMESTAMPTZ NOT NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Dropping epoch history permits reuse of old fencing tokens. Require
        // deployment containment and explicit destructive reset instead.
        $this->throwIrreversibleMigrationException('Deployment ownership history must not be dropped automatically.');
    }
}
