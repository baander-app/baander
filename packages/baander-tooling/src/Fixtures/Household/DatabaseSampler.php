<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Household;

use DateTimeImmutable;
use Exception;
use DateTimeZone;
use JsonException;
use RuntimeException;

final class DatabaseSampler
{
    private readonly string $from;
    /** @var array<string, string> */
    private readonly array $libraries;

    /**
     * @param array<string, string> $libraries
     * @param list<string> $scheduledJobIds
     */
    public function __construct(array $libraries, string $from, private readonly array $scheduledJobIds = [])
    {
        $keys = array_keys($libraries);
        sort($keys);
        if ($keys !== ['episodes', 'movies', 'music'] || count(array_unique($libraries)) !== 3) {
            throw new RuntimeException('Expected three distinct fixture library UUIDs keyed music, movies and episodes');
        }
        foreach ($libraries as $id) {
            self::uuid($id, 'library');
        }
        if (count($scheduledJobIds) > 16 || count(array_unique($scheduledJobIds)) !== count($scheduledJobIds)) {
            throw new RuntimeException('Expected at most sixteen distinct fixture schedule UUIDs');
        }
        foreach ($scheduledJobIds as $id) {
            self::uuid($id, 'schedule');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $from)) {
            throw new RuntimeException('Expected a timezone-qualified fixture start timestamp');
        }
        try {
            $instant = new DateTimeImmutable($from);
        } catch (Exception $error) {
            throw new RuntimeException('Invalid fixture start timestamp', previous: $error);
        }
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new RuntimeException('Invalid fixture start timestamp');
        }
        $this->from = $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
        $this->libraries = array_map(strtolower(...), $libraries);
    }

    private static function uuid(string $id, string $kind): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id)) {
            throw new RuntimeException('Invalid fixture ' . $kind . ' UUID');
        }
    }

    public function sql(): string
    {
        $music = $this->libraries['music'];
        $movies = $this->libraries['movies'];
        $episodes = $this->libraries['episodes'];
        $schedules = implode(', ', array_map(static fn(string $id): string => "'{$id}'::uuid", $this->scheduledJobIds));
        $schedules = $schedules === '' ? 'NULL::uuid' : $schedules;
        return <<<SQL
            BEGIN READ ONLY;
            SET LOCAL statement_timeout = '10s';
            SET LOCAL lock_timeout = '1s';
            SET LOCAL TIME ZONE 'UTC';
            WITH fixture(role, id) AS (
                VALUES ('music', '{$music}'::uuid), ('movies', '{$movies}'::uuid), ('episodes', '{$episodes}'::uuid)
            ), catalog AS (
                SELECT a.library_id, s.id, s.path, 'song' AS kind, s.mbid IS NOT NULL AND s.mbid <> '' AS enriched
                FROM songs s JOIN albums a ON a.id = s.album_id JOIN fixture f ON f.id = a.library_id
                UNION ALL
                SELECT m.library_id, v.id, v.path, 'video' AS kind, m.tmdb_id IS NOT NULL AS enriched
                FROM movies m JOIN movie_video mv ON mv.movie_id = m.id JOIN videos v ON v.id = mv.video_id
                JOIN fixture f ON f.id = m.library_id
            ), library_observations AS (
                SELECT f.role, jsonb_build_object(
                    'id', f.id, 'exists', l.id IS NOT NULL, 'type', l.type, 'path', l.path,
                    'scanStatus', l.scan_status, 'lastScan', l.last_scan,
                    'claimId', l.claim_id, 'claimKind', l.claim_kind, 'claimExpiresAt', l.claim_expires_at,
                    'claimExpired', l.claim_expires_at < statement_timestamp(),
                    'indexedFiles', (SELECT count(*) FROM library_file_index i WHERE i.library_id = f.id),
                    'indexedAvailableFiles', (SELECT count(*) FROM library_file_index i WHERE i.library_id = f.id AND i.available),
                    'indexedUnavailableFiles', (SELECT count(*) FROM library_file_index i WHERE i.library_id = f.id AND NOT i.available),
                    'indexedAvailablePathHash', (SELECT md5(string_agg(i.path, E'\\n' ORDER BY i.path)) FROM library_file_index i WHERE i.library_id = f.id AND i.available),
                    'indexedPathHash', (SELECT md5(string_agg(i.path, E'\\n' ORDER BY i.path)) FROM library_file_index i WHERE i.library_id = f.id),
                    'songs', (SELECT count(*) FROM catalog c WHERE c.library_id = f.id AND c.kind = 'song'),
                    'songsWithMbid', (SELECT count(*) FROM catalog c WHERE c.library_id = f.id AND c.kind = 'song' AND c.enriched),
                    'albums', (SELECT count(*) FROM albums a WHERE a.library_id = f.id),
                    'albumsWithMbid', (SELECT count(*) FROM albums a WHERE a.library_id = f.id AND a.mbid IS NOT NULL AND a.mbid <> ''),
                    'movies', (SELECT count(*) FROM movies m WHERE m.library_id = f.id),
                    'moviesWithTmdbId', (SELECT count(*) FROM movies m WHERE m.library_id = f.id AND m.tmdb_id IS NOT NULL),
                    'videos', (SELECT count(DISTINCT c.id) FROM catalog c WHERE c.library_id = f.id AND c.kind = 'video'),
                    'catalogPathHash', (SELECT md5(string_agg(c.path, E'\\n' ORDER BY c.path)) FROM catalog c WHERE c.library_id = f.id),
                    'missingCatalogFiles', (SELECT count(*) FROM library_file_index i WHERE i.library_id = f.id AND i.available AND NOT EXISTS (SELECT FROM catalog c WHERE c.library_id = f.id AND c.path = i.path)),
                    'missingCatalogSamples', (SELECT coalesce(jsonb_agg(m.path), '[]'::jsonb) FROM (SELECT i.path FROM library_file_index i WHERE i.library_id = f.id AND i.available AND NOT EXISTS (SELECT FROM catalog c WHERE c.library_id = f.id AND c.path = i.path) ORDER BY i.path LIMIT 10) m)
                ) AS observation
                FROM fixture f LEFT JOIN libraries l ON l.id = f.id
            ), window_jobs AS (
                SELECT id, job_uuid, name, queue, status, attempt, retried, exception IS NOT NULL AS has_exception, exception_class, created_at
                FROM job_monitors WHERE (created_at >= '{$this->from}'::timestamptz OR updated_at >= '{$this->from}'::timestamptz OR status IN ('queued', 'running')) AND created_at <= statement_timestamp()
            ), job_groups AS (
                SELECT name, queue, status, count(*) AS count, sum(attempt) AS attempts, max(attempt) AS max_attempt,
                    count(*) FILTER (WHERE retried) AS retried, count(*) FILTER (WHERE has_exception) AS with_exception,
                    md5(string_agg(id::text, ',' ORDER BY id)) AS observed_ids_hash
                FROM window_jobs GROUP BY name, queue, status
            ), fixture_schedules AS (
                SELECT * FROM scheduled_jobs WHERE id = ANY (ARRAY[{$schedules}])
            ), fixture_occurrences AS (
                SELECT o.* FROM scheduler_occurrences o WHERE o.job_id = ANY (ARRAY[{$schedules}])
                    AND o.scheduled_for >= '{$this->from}'::timestamptz AND o.scheduled_for <= statement_timestamp()
            ), connection_groups AS (
                SELECT datname, backend_type, application_name, state, wait_event_type, count(*) AS count
                FROM pg_stat_activity GROUP BY datname, backend_type, application_name, state, wait_event_type
            )
            SELECT jsonb_build_object(
                'version', 1, 'observedAt', statement_timestamp(), 'from', '{$this->from}',
                'libraries', (SELECT jsonb_object_agg(role, observation) FROM library_observations),
                'jobs', jsonb_build_object('scope', 'deployment-active-or-changed-in-window', 'count', (SELECT count(*) FROM window_jobs),
                    'groupCount', (SELECT count(*) FROM job_groups),
                    'groups', (SELECT coalesce(jsonb_agg(to_jsonb(g)), '[]'::jsonb) FROM (SELECT * FROM job_groups ORDER BY name, queue, status LIMIT 100) g),
                    'failureSamples', (SELECT coalesce(jsonb_agg(to_jsonb(j)), '[]'::jsonb) FROM (SELECT id, job_uuid, name, status, attempt, exception_class FROM window_jobs WHERE status = 'failed' OR has_exception ORDER BY created_at, id LIMIT 10) j)),
                'outbox', (SELECT jsonb_build_object('scope', 'deployment-current', 'pending', count(*) FILTER (WHERE relayed_at IS NULL AND dead_lettered_at IS NULL), 'dead', count(*) FILTER (WHERE dead_lettered_at IS NOT NULL), 'maxAttempts', coalesce(max(attempts), 0)) FROM domain_event_outbox),
                'schedules', (SELECT coalesce(jsonb_agg(jsonb_build_object('id', s.id, 'status', s.status, 'lastRunAt', s.last_run_at, 'lastFailureAt', s.last_failure_at, 'lastResult', s.last_result, 'runCount', s.run_count,
                    'occurrences', (SELECT count(*) FROM fixture_occurrences o WHERE o.job_id = s.id),
                    'dispatched', (SELECT count(*) FROM fixture_occurrences o WHERE o.job_id = s.id AND o.dispatched_at IS NOT NULL),
                    'returned', (SELECT count(*) FROM fixture_occurrences o WHERE o.job_id = s.id AND EXISTS (SELECT FROM scheduler_occurrence_executions e WHERE e.occurrence_id = o.id AND e.returned_at IS NOT NULL)),
                    'executionAttempts', (SELECT count(*) FROM fixture_occurrences o JOIN scheduler_occurrence_executions e ON e.occurrence_id = o.id WHERE o.job_id = s.id)
                ) ORDER BY s.id), '[]'::jsonb) FROM fixture_schedules s),
                'connections', (SELECT coalesce(jsonb_agg(to_jsonb(c)), '[]'::jsonb) FROM (SELECT * FROM connection_groups ORDER BY datname, backend_type, application_name, state, wait_event_type LIMIT 100) c),
                'connectionGroupCount', (SELECT count(*) FROM connection_groups),
                'database', (SELECT jsonb_build_object('datname', datname, 'numbackends', numbackends, 'temp_files', temp_files, 'temp_bytes', temp_bytes, 'deadlocks', deadlocks, 'stats_reset', stats_reset) FROM pg_stat_database WHERE datname = current_database()),
                'vacuumTables', (SELECT coalesce(jsonb_agg(to_jsonb(t)), '[]'::jsonb) FROM (SELECT s.schemaname, s.relname, s.n_live_tup, s.n_dead_tup, s.last_autovacuum, s.last_autoanalyze, s.autovacuum_count, s.autoanalyze_count, c.reloptions FROM pg_stat_user_tables s JOIN pg_class c ON c.oid = s.relid WHERE s.relname IN ('libraries', 'library_file_index', 'songs', 'albums', 'movies', 'movie_video', 'videos', 'job_monitors', 'domain_event_outbox', 'scheduled_jobs', 'scheduler_occurrences', 'scheduler_occurrence_executions') ORDER BY s.schemaname, s.relname) t),
                'vacuumProgress', (SELECT coalesce(jsonb_agg(to_jsonb(v)), '[]'::jsonb) FROM (SELECT pid, datname, relid, phase, heap_blks_total, heap_blks_scanned, heap_blks_vacuumed FROM pg_stat_progress_vacuum WHERE datname = current_database() ORDER BY pid LIMIT 100) v),
                'settings', (SELECT jsonb_object_agg(name, jsonb_build_object('setting', setting, 'unit', unit, 'source', source, 'pendingRestart', pending_restart)) FROM pg_settings WHERE name IN ('max_connections', 'superuser_reserved_connections', 'reserved_connections', 'shared_buffers', 'work_mem', 'hash_mem_multiplier', 'maintenance_work_mem', 'autovacuum_work_mem', 'autovacuum', 'autovacuum_max_workers', 'autovacuum_worker_slots', 'autovacuum_naptime', 'autovacuum_vacuum_threshold', 'autovacuum_vacuum_scale_factor', 'autovacuum_vacuum_cost_limit', 'autovacuum_vacuum_cost_delay', 'max_parallel_workers', 'max_parallel_workers_per_gather', 'track_counts', 'stats_fetch_consistency'))
            );
            COMMIT;
            SQL;
    }

    /** @return array<string, mixed> */
    public function parse(string $psqlOutput): array
    {
        if (strlen($psqlOutput) > 1_048_576) {
            throw new RuntimeException('Database snapshot exceeded one MiB');
        }
        try {
            $snapshot = json_decode(trim($psqlOutput), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Invalid database snapshot JSON', previous: $error);
        }
        if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== 1 || isset($snapshot['error']) || ($snapshot['from'] ?? null) !== $this->from || !is_string($snapshot['observedAt'] ?? null)) {
            throw new RuntimeException('Invalid database snapshot envelope');
        }
        foreach (['libraries', 'jobs', 'outbox', 'schedules', 'connections', 'database', 'vacuumTables', 'vacuumProgress', 'settings'] as $section) {
            if (!isset($snapshot[$section]) || !is_array($snapshot[$section])) {
                throw new RuntimeException('Missing database snapshot section: ' . $section);
            }
        }
        if ($snapshot['settings'] === [] || $snapshot['database'] === []) {
            throw new RuntimeException('Missing database snapshot runtime observations');
        }
        foreach (['count', 'groupCount'] as $count) {
            self::count($snapshot['jobs'], $count);
        }
        foreach (['pending', 'dead', 'maxAttempts'] as $count) {
            self::count($snapshot['outbox'], $count);
        }
        foreach (['temp_files', 'temp_bytes', 'deadlocks'] as $count) {
            self::count($snapshot['database'], $count);
        }
        foreach (['groups' => 100, 'failureSamples' => 10] as $key => $limit) {
            if (!isset($snapshot['jobs'][$key]) || !is_array($snapshot['jobs'][$key]) || !array_is_list($snapshot['jobs'][$key]) || count($snapshot['jobs'][$key]) > $limit) {
                throw new RuntimeException('Invalid database snapshot job list: ' . $key);
            }
        }
        foreach (['schedules' => 16, 'connections' => 100, 'vacuumProgress' => 100] as $key => $limit) {
            if (!array_is_list($snapshot[$key]) || count($snapshot[$key]) > $limit) {
                throw new RuntimeException('Invalid database snapshot list: ' . $key);
            }
        }
        foreach ($this->libraries as $role => $id) {
            $library = $snapshot['libraries'][$role] ?? null;
            if (!is_array($library) || ($library['id'] ?? null) !== $id || !is_bool($library['exists'] ?? null) || !is_array($library['missingCatalogSamples'] ?? null)) {
                throw new RuntimeException('Invalid database snapshot library: ' . $role);
            }
            foreach (['indexedFiles', 'indexedAvailableFiles', 'indexedUnavailableFiles', 'songs', 'albums', 'movies', 'videos', 'songsWithMbid', 'albumsWithMbid', 'moviesWithTmdbId', 'missingCatalogFiles'] as $count) {
                self::count($library, $count);
            }
            if ($library['indexedAvailableFiles'] + $library['indexedUnavailableFiles'] !== $library['indexedFiles']
                || $library['missingCatalogFiles'] > $library['indexedAvailableFiles']
                || !array_is_list($library['missingCatalogSamples'])
                || count($library['missingCatalogSamples']) !== min(10, $library['missingCatalogFiles'])) {
                throw new RuntimeException('Contradictory database snapshot availability evidence: ' . $role);
            }
            foreach ($library['missingCatalogSamples'] as $path) {
                if (!is_string($path) || $path === '') {
                    throw new RuntimeException('Invalid database snapshot missing-import path');
                }
            }
        }
        return $snapshot;
    }

    /** @param array<string, mixed> $observation */
    private static function count(array $observation, string $key): void
    {
        if (!isset($observation[$key]) || !is_int($observation[$key]) || $observation[$key] < 0) {
            throw new RuntimeException('Invalid database snapshot count: ' . $key);
        }
    }
}
