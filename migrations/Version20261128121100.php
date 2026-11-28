<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128121100 extends AbstractMigration
{
    public const string RECOVERY_JOB_ID = '01a2221e-ed3d-7870-a5e4-1944229e886f';
    public const string RECOVERY_COMMAND = 'App\\Catalog\\Application\\Command\\RecoverVideoMatchQueueCommand';
    public const string RECOVERY_EXPRESSION = '* * * * *';

    private const string MOVIE_COLUMNS = 'id,public_id,library_id,title,year,summary,tmdb_id,imdb_id,overview,tagline,poster_url,backdrop_url,runtime,rating,original_language,tmdb_collection_id,collection_name,locked_fields,created_at,matched_provider,provider_id,external_ids,hand_matched';
    private const string MATCH_COLUMNS = 'id,series_id,movie_id,status,source,due_at,reason,best_score,checked_at';

    public function getDescription(): string
    {
        return 'Queue video title matches with fenced claims, provider pauses and movie match identities.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql(<<<'SQL'
            ALTER TABLE movies
                ADD matched_provider TEXT,
                ADD provider_id TEXT,
                ADD external_ids JSONB NOT NULL DEFAULT '{}',
                ADD hand_matched BOOLEAN NOT NULL DEFAULT FALSE,
                ADD CONSTRAINT chk_movies_match_identity CHECK ((matched_provider IS NULL) = (provider_id IS NULL))
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE video_match_states (
                id UUID NOT NULL,
                series_id UUID,
                movie_id UUID,
                status TEXT NOT NULL,
                source TEXT NOT NULL,
                due_at TIMESTAMPTZ NOT NULL,
                claim_id UUID,
                claim_expires_at TIMESTAMPTZ,
                next_due_at TIMESTAMPTZ,
                reason TEXT,
                best_score SMALLINT,
                checked_at TIMESTAMPTZ,
                CONSTRAINT video_match_states_pkey PRIMARY KEY (id),
                CONSTRAINT chk_video_match_states_target CHECK ((series_id IS NULL) <> (movie_id IS NULL)),
                CONSTRAINT chk_video_match_states_status CHECK (status IN ('requested', 'matched', 'needs_review', 'awaiting_provider')),
                CONSTRAINT chk_video_match_states_source CHECK (source IN ('ingest', 'admin')),
                CONSTRAINT chk_video_match_states_claim CHECK ((claim_id IS NULL) = (claim_expires_at IS NULL) AND (claim_id IS NULL OR status = 'requested') AND (next_due_at IS NULL OR claim_id IS NOT NULL)),
                CONSTRAINT chk_video_match_states_score CHECK (best_score BETWEEN 0 AND 100),
                CONSTRAINT chk_video_match_states_reason CHECK (reason IN ('no_candidates', 'low_score', 'close_runner_up', 'duplicate_in_library', 'duplicate_file', 'episode_collision', 'name_disagreement', 'login_failed', 'unavailable', 'provider_paused', 'refused')),
                CONSTRAINT chk_video_match_states_review CHECK (status <> 'needs_review' OR (reason IS NOT NULL AND checked_at IS NOT NULL))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_video_match_states_series_id ON video_match_states (series_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_video_match_states_movie_id ON video_match_states (movie_id)');
        $this->addSql('CREATE INDEX idx_video_match_states_status_due_at_id ON video_match_states (status, due_at, id)');
        $this->addSql('ALTER TABLE video_match_states ADD CONSTRAINT fk_video_match_states_series_id FOREIGN KEY (series_id) REFERENCES tv_series (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE video_match_states ADD CONSTRAINT fk_video_match_states_movie_id FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE CASCADE');
        $this->addSql(<<<'SQL'
            CREATE TABLE video_provider_pauses (
                provider TEXT NOT NULL,
                failure_kind TEXT NOT NULL,
                failure_count SMALLINT NOT NULL,
                retry_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT video_provider_pauses_pkey PRIMARY KEY (provider),
                CONSTRAINT chk_video_provider_pauses_failure_count CHECK (failure_count >= 1 AND failure_count <= 4)
            )
            SQL);
        foreach ([
            'movies' => [self::MOVIE_COLUMNS, '{catalog,libraries,activity,recommendations,admin-media,admin-metadata}', '{library}'],
            'video_match_states' => [self::MATCH_COLUMNS, '{admin-metadata}', '{admin}'],
            'video_provider_pauses' => ['provider,failure_kind,failure_count,retry_at', '{admin-metadata}', '{admin}'],
        ] as $table => [$columns, $families, $routes]) {
            $arguments = "'{" . $columns . "}', '" . $families . "', '" . $routes . "'";
            $this->addSql("CREATE OR REPLACE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
            $this->addSql("CREATE OR REPLACE TRIGGER query_capture_insert AFTER INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
        }
        // Recovery is installed data, so its seed publishes no administrator change.
        $this->addSql('ALTER TABLE scheduled_jobs DISABLE TRIGGER query_capture_insert');
        $this->addSql(
            <<<'SQL'
                WITH sampled_clock AS MATERIALIZED (SELECT clock_timestamp() AS now)
                INSERT INTO scheduled_jobs (
                    id, public_id, revision, name, expression, job_type, command, status,
                    description, parameters, created_at, updated_at, next_run_at, recovery_after, run_count
                )
                SELECT CAST(:id AS uuid), translate(substr(encode(gen_random_bytes(16), 'base64'), 1, 21), '+/', '-_'),
                    gen_random_uuid(), 'Recover the video match queue', :expression, 'messenger', :command, 'active',
                    'Restarts due video matching and requeues titles whose providers became usable.',
                    CAST('[]' AS json), now, now,
                    date_bin(INTERVAL '1 minute', now, TIMESTAMPTZ '2000-01-01 00:00:00+00') + INTERVAL '1 minute',
                    now, 0
                FROM sampled_clock
                SQL,
            [
                'id' => self::RECOVERY_JOB_ID,
                'expression' => self::RECOVERY_EXPRESSION,
                'command' => self::RECOVERY_COMMAND,
            ],
        );
        $this->addSql('ALTER TABLE scheduled_jobs ENABLE TRIGGER query_capture_insert');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing video matching would discard requests, provider pauses and administrator match choices.');
    }
}
