<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126211000 extends AbstractMigration
{
    public const string PURGE_JOB_ID = '0199bf3c-8a00-7000-8000-0a07c0de9e06';
    public const string PURGE_COMMAND = 'App\\Auth\\Application\\Command\\OAuth\\PurgeRevokedPersonalAccessClientsCommand';
    public const string PURGE_EXPRESSION = '45 3 * * *';

    public function getDescription(): string
    {
        return 'Schedule the daily purge of revoked personal access clients.';
    }

    public function up(Schema $schema): void
    {
        // A revoked personal access client keeps its row and its revoked tokens until something
        // deletes them. The schedule starts at its next 03:45 UTC, from one clock sample. The
        // seed is installed data, not a change an admin made, so its insert publishes no live update.
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql('ALTER TABLE scheduled_jobs DISABLE TRIGGER query_capture_insert');
        $this->addSql(
            <<<'SQL'
                WITH sampled_clock AS MATERIALIZED (SELECT clock_timestamp() AS now)
                INSERT INTO scheduled_jobs (
                    id, public_id, revision, name, expression, job_type, command, status,
                    description, parameters, created_at, updated_at, next_run_at, recovery_after, run_count
                )
                SELECT CAST(:id AS uuid), translate(substr(encode(gen_random_bytes(16), 'base64'), 1, 21), '+/', '-_'),
                    gen_random_uuid(), 'Purge revoked personal access clients', :expression, 'messenger', :command, 'active',
                    'Deletes revoked personal access clients and the tokens issued to them.',
                    CAST('[]' AS json), now, now,
                    (date_trunc('day', now AT TIME ZONE 'UTC') + INTERVAL '3 hours 45 minutes'
                        + CASE WHEN date_trunc('day', now AT TIME ZONE 'UTC') + INTERVAL '3 hours 45 minutes' <= now AT TIME ZONE 'UTC'
                            THEN INTERVAL '1 day' ELSE INTERVAL '0' END) AT TIME ZONE 'UTC',
                    now, 0
                FROM sampled_clock
                SQL,
            [
                'id' => self::PURGE_JOB_ID,
                'expression' => self::PURGE_EXPRESSION,
                'command' => self::PURGE_COMMAND,
            ],
        );
        $this->addSql('ALTER TABLE scheduled_jobs ENABLE TRIGGER query_capture_insert');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM scheduled_jobs WHERE id = :id', ['id' => self::PURGE_JOB_ID]);
    }
}
