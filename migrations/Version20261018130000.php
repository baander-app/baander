<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reserve fixed control and execution units under a joint deployment retirement barrier.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql("SET LOCAL statement_timeout = '30s'");
        // Controllers must be quiescent during upgrade. These locks close the
        // preflight-to-DDL gap even when an old journal writer is still connected.
        $this->addSql('LOCK TABLE worker_deployment_creations, worker_deployment_containers, worker_deployment_retirements, worker_deployment_leases IN ACCESS EXCLUSIVE MODE');
        $this->addSql(<<<'SQL'
DO $$
DECLARE unresolved text;
BEGIN
    SELECT string_agg(namespace || '/' || boot_id, ', ' ORDER BY namespace, boot_id)
    INTO unresolved
    FROM (
        SELECT namespace, owner_boot_id AS boot_id FROM worker_deployment_leases WHERE state = 'active'
        UNION
        SELECT c.namespace, c.boot_id FROM worker_deployment_creations c
        WHERE NOT EXISTS (
            SELECT 1 FROM worker_deployment_containers b
            JOIN worker_deployment_retirements r USING (namespace, boot_id, daemon_id, container_id)
            WHERE b.namespace = c.namespace AND b.boot_id = c.boot_id AND b.daemon_id = c.daemon_id AND r.completed_at IS NOT NULL
        )
        UNION
        SELECT b.namespace, b.boot_id FROM worker_deployment_containers b
        WHERE NOT EXISTS (
            SELECT 1 FROM worker_deployment_creations c
            JOIN worker_deployment_retirements r USING (namespace, boot_id, daemon_id)
            WHERE c.namespace = b.namespace AND c.boot_id = b.boot_id AND c.daemon_id = b.daemon_id
                AND r.container_id = b.container_id AND r.completed_at IS NOT NULL
        )
        UNION
        SELECT r.namespace, r.boot_id FROM worker_deployment_retirements r
        WHERE r.completed_at IS NULL OR NOT EXISTS (
            SELECT 1 FROM worker_deployment_containers b
            JOIN worker_deployment_creations c USING (namespace, boot_id, daemon_id)
            WHERE b.namespace = r.namespace AND b.boot_id = r.boot_id AND b.daemon_id = r.daemon_id AND b.container_id = r.container_id
        )
    ) blocked;
    IF unresolved IS NOT NULL THEN
        RAISE EXCEPTION 'Unresolved or inconsistent singleton deployments must be contained before upgrade: %', unresolved;
    END IF;
END $$
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE worker_deployment_boots (
    namespace text NOT NULL CHECK (namespace ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$'),
    boot_id text NOT NULL CHECK (boot_id ~ '^[0-9a-f]{32}$'),
    daemon_id text NOT NULL CHECK (daemon_id ~ '^[A-Za-z0-9_.:-]{1,128}$'),
    manifest_hash text CHECK (manifest_hash ~ '^[0-9a-f]{64}$'),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    retirement_requested_at timestamptz,
    retired_at timestamptz,
    imported_singleton boolean NOT NULL DEFAULT false,
    PRIMARY KEY (namespace, boot_id),
    CONSTRAINT uniq_worker_deployment_boots_namespace_boot_id_daemon_id UNIQUE (namespace, boot_id, daemon_id),
    CHECK (retired_at IS NULL OR retirement_requested_at IS NOT NULL),
    CHECK ((NOT imported_singleton AND manifest_hash IS NOT NULL) OR (imported_singleton AND retired_at IS NOT NULL))
)
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO worker_deployment_boots (namespace, boot_id, daemon_id, created_at, retirement_requested_at, retired_at, imported_singleton)
SELECT c.namespace, c.boot_id, c.daemon_id, c.created_at, r.intended_at, r.completed_at, true
FROM worker_deployment_creations c JOIN worker_deployment_retirements r USING (namespace, boot_id, daemon_id)
SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_worker_deployment_boots_live_namespace ON worker_deployment_boots (namespace) WHERE retired_at IS NULL');
        $this->addSql("ALTER TABLE worker_deployment_creations ADD unit text NOT NULL DEFAULT 'control', ADD claimed_at timestamptz, ADD cancelled_at timestamptz, ALTER recipe_hash DROP NOT NULL");
        $this->addSql('UPDATE worker_deployment_creations SET claimed_at = created_at');
        foreach (['creations', 'containers', 'retirements'] as $table) {
            if ($table !== 'creations') {
                $this->addSql("ALTER TABLE worker_deployment_{$table} ADD unit text NOT NULL DEFAULT 'control'");
            }
            $this->addSql("ALTER TABLE worker_deployment_{$table} ALTER unit DROP DEFAULT, DROP CONSTRAINT worker_deployment_{$table}_pkey, ADD PRIMARY KEY (namespace, boot_id, unit), ADD CONSTRAINT chk_worker_deployment_{$table}_unit CHECK (unit IN ('control', 'execution'))");
        }
        $this->addSql(<<<'SQL'
ALTER TABLE worker_deployment_creations
    ADD CONSTRAINT fk_worker_deployment_creations_namespace_boot_id_daemon_id FOREIGN KEY (namespace, boot_id, daemon_id) REFERENCES worker_deployment_boots (namespace, boot_id, daemon_id),
    ADD CONSTRAINT uniq_worker_deployment_creations_binding UNIQUE (namespace, boot_id, unit, daemon_id),
    ADD CONSTRAINT chk_worker_deployment_creations_state CHECK (
        (claimed_at IS NULL OR (recipe_hash IS NOT NULL AND cancelled_at IS NULL))
        AND (recipe_hash IS NOT NULL OR (cancelled_at IS NOT NULL AND claimed_at IS NULL))
    )
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO worker_deployment_creations (namespace, boot_id, daemon_id, unit, recipe_hash, created_at, cancelled_at)
SELECT namespace, boot_id, daemon_id, 'execution', NULL, created_at, retired_at FROM worker_deployment_boots
SQL);
        $this->addSql(<<<'SQL'
ALTER TABLE worker_deployment_containers
    ADD CONSTRAINT fk_worker_deployment_containers_creation FOREIGN KEY (namespace, boot_id, unit, daemon_id)
        REFERENCES worker_deployment_creations (namespace, boot_id, unit, daemon_id),
    ADD CONSTRAINT uniq_worker_deployment_containers_binding UNIQUE (namespace, boot_id, unit, daemon_id, container_id)
SQL);
        $this->addSql(<<<'SQL'
ALTER TABLE worker_deployment_retirements
    ADD CONSTRAINT fk_worker_deployment_retirements_binding FOREIGN KEY (namespace, boot_id, unit, daemon_id, container_id)
        REFERENCES worker_deployment_containers (namespace, boot_id, unit, daemon_id, container_id)
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Paired deployment claims and containment evidence cannot be downgraded to singleton ownership.');
    }
}
