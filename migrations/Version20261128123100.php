<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128123100 extends AbstractMigration
{
    public const string REFRESH_JOB_ID = '01a22224-553b-7708-87b8-d410a61bab19';
    public const string REFRESH_COMMAND = 'App\\Catalog\\Application\\Command\\RefreshVideoMatchesCommand';
    public const string REFRESH_EXPRESSION = '15 4 * * *';

    public function getDescription(): string
    {
        return 'Schedule matched video metadata refresh and record unavailable episode orderings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql(<<<'SQL'
            ALTER TABLE video_match_states
                DROP CONSTRAINT chk_video_match_states_reason,
                ADD CONSTRAINT chk_video_match_states_reason CHECK (reason IN (
                    'no_candidates', 'low_score', 'close_runner_up', 'duplicate_in_library',
                    'duplicate_file', 'episode_collision', 'name_disagreement', 'login_failed',
                    'unavailable', 'provider_paused', 'refused', 'ordering_unavailable'
                ))
            SQL);
        $this->addSql('ALTER TABLE scheduled_jobs DISABLE TRIGGER query_capture_insert');
        $this->addSql(
            <<<'SQL'
                WITH sampled_clock AS MATERIALIZED (SELECT clock_timestamp() AS now),
                schedule AS (
                    SELECT now, date_trunc('day', now, 'UTC') + INTERVAL '4 hours 15 minutes' AS today
                    FROM sampled_clock
                )
                INSERT INTO scheduled_jobs (
                    id, public_id, revision, name, expression, job_type, command, status,
                    description, parameters, created_at, updated_at, next_run_at, recovery_after, run_count
                )
                SELECT CAST(:id AS uuid), translate(substr(encode(gen_random_bytes(16), 'base64'), 1, 21), '+/', '-_'),
                    gen_random_uuid(), 'Refresh matched video titles', :expression, 'messenger', :command, 'active',
                    'Refreshes matched movies and TV series from their saved providers and episode orderings.',
                    CAST('[]' AS json), now, now,
                    CASE WHEN today > now THEN today ELSE today + INTERVAL '24 hours' END,
                    now, 0
                FROM schedule
                SQL,
            [
                'id' => self::REFRESH_JOB_ID,
                'expression' => self::REFRESH_EXPRESSION,
                'command' => self::REFRESH_COMMAND,
            ],
        );
        $this->addSql('ALTER TABLE scheduled_jobs ENABLE TRIGGER query_capture_insert');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing refresh would discard unavailable ordering review results.');
    }
}
