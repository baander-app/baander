<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Fixtures\Qualification\DatabaseSampler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QualificationDatabaseSamplerTest extends TestCase
{
    private const array LIBRARIES = [
        'music' => '11111111-1111-4111-8111-111111111111',
        'movies' => '22222222-2222-4222-8222-222222222222',
        'episodes' => '33333333-3333-4333-8333-333333333333',
    ];

    public function testRejectsInvalidOrAliasedLibraryIdentities(): void
    {
        foreach ([[], ['music' => 'invalid'] + self::LIBRARIES, ['episodes' => self::LIBRARIES['movies']] + self::LIBRARIES] as $libraries) {
            try {
                new DatabaseSampler($libraries, '2026-11-02T13:00:00Z');
                self::fail('Invalid library identity accepted');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('librar', $error->getMessage());
            }
        }
    }

    public function testRejectsUnzonedOrInvalidTimestampAndInvalidScheduleId(): void
    {
        foreach (['2026-11-02 13:00:00', '2026-02-30T13:00:00Z', '2026-11-02T13:00:00+99:99', "2026-11-02T13:00:00Z'; DROP TABLE songs;"] as $from) {
            try {
                new DatabaseSampler(self::LIBRARIES, $from);
                self::fail('Invalid timestamp accepted');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('timestamp', $error->getMessage());
            }
        }
        $this->expectExceptionMessage('schedule');
        new DatabaseSampler(self::LIBRARIES, '2026-11-02T13:00:00Z', ['invalid']);
    }

    public function testSqlCollectsBoundedReadOnlyEvidenceFromActualCatalogAndRuntime(): void
    {
        $sql = new DatabaseSampler(self::LIBRARIES, '2026-11-02T14:00:00+01:00')->sql();
        foreach (['BEGIN READ ONLY', "statement_timeout = '10s'", 'library_file_index', 'JOIN albums', 'FROM songs', 'FROM movies', 'JOIN movie_video', 'JOIN videos', 'tmdb_id', 'mbid', 'LIMIT 10', 'job_monitors', 'scheduler_occurrences', 'scheduler_occurrence_executions', 'domain_event_outbox', 'pg_stat_activity', 'pg_stat_database', 'stats_reset', 'pg_stat_user_tables', 'pg_stat_progress_vacuum', 'pg_settings', '2026-11-02T13:00:00.000000+00:00'] as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }
        self::assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE|TRUNCATE)\b/i', $sql);
    }

    public function testParsesOnlyCompleteMatchingSnapshot(): void
    {
        $sampler = new DatabaseSampler(self::LIBRARIES, '2026-11-02T13:00:00Z');
        $snapshot = $this->snapshot();
        self::assertSame($snapshot, $sampler->parse(" \n" . json_encode($snapshot, JSON_THROW_ON_ERROR) . "\n"));
        foreach (['', '{}', '[]', 'null', 'ERROR: relation missing', json_encode(array_diff_key($snapshot, ['settings' => true]), JSON_THROW_ON_ERROR), json_encode(array_replace($snapshot, ['error' => 'failed']), JSON_THROW_ON_ERROR), json_encode(array_replace($snapshot, ['libraries' => []]), JSON_THROW_ON_ERROR), json_encode(array_replace($snapshot, ['database' => null]), JSON_THROW_ON_ERROR)] as $bad) {
            try {
                $sampler->parse($bad);
                self::fail('Incomplete snapshot accepted');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('snapshot', $error->getMessage());
            }
        }
    }

    public function testRejectsMissingCountsAndUnboundedEvidence(): void
    {
        $sampler = new DatabaseSampler(self::LIBRARIES, '2026-11-02T13:00:00Z');
        $badCount = $this->snapshot();
        $badCount['libraries']['music']['songs'] = -1;
        $missingJobs = $this->snapshot();
        $missingJobs['jobs'] = [];
        $tooManyFailures = $this->snapshot();
        $tooManyFailures['jobs']['failureSamples'] = array_fill(0, 11, ['id' => 'unexpected']);
        foreach ([$badCount, $missingJobs, $tooManyFailures] as $snapshot) {
            try {
                $sampler->parse(json_encode($snapshot, JSON_THROW_ON_ERROR));
                self::fail('Invalid snapshot counts or bounds accepted');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('snapshot', $error->getMessage());
            }
        }
    }

    public function testScheduleIdsAreExplicitAndOutputSizeIsBounded(): void
    {
        $sampler = new DatabaseSampler(self::LIBRARIES, '2026-11-02T13:00:00Z', [self::LIBRARIES['music']]);
        self::assertStringContainsString("ANY (ARRAY['" . self::LIBRARIES['music'] . "'::uuid])", $sampler->sql());
        $this->expectExceptionMessage('exceeded one MiB');
        $sampler->parse(str_repeat(' ', 1_048_577));
    }

    public function testMissingImportsOnlyInspectAvailablePathsButKeepHistoricalIndexEvidence(): void
    {
        $sampler = new DatabaseSampler(self::LIBRARIES, '2026-11-02T13:00:00Z');
        $sql = $sampler->sql();
        self::assertStringContainsString("'missingCatalogFiles', (SELECT count(*) FROM library_file_index i WHERE i.library_id = f.id AND i.available AND NOT EXISTS", $sql);
        self::assertStringContainsString("'missingCatalogSamples', (SELECT coalesce(jsonb_agg(m.path), '[]'::jsonb) FROM (SELECT i.path FROM library_file_index i WHERE i.library_id = f.id AND i.available AND NOT EXISTS", $sql);
        self::assertStringContainsString("'indexedUnavailableFiles', (SELECT count(*) FROM library_file_index i WHERE i.library_id = f.id AND NOT i.available)", $sql);
        self::assertStringContainsString("'indexedAvailablePathHash'", $sql);
        $snapshot = $this->snapshot();
        $snapshot['libraries']['music'] = array_replace($snapshot['libraries']['music'], [
            'indexedFiles' => 2, 'indexedAvailableFiles' => 1, 'indexedUnavailableFiles' => 1,
            'songs' => 1, 'missingCatalogFiles' => 0, 'missingCatalogSamples' => [],
        ]);
        self::assertSame(1, $sampler->parse(json_encode($snapshot, JSON_THROW_ON_ERROR))['libraries']['music']['indexedUnavailableFiles']);
    }

    public function testRejectsContradictoryAvailabilityCountsAndMissingImportSamples(): void
    {
        $sampler = new DatabaseSampler(self::LIBRARIES, '2026-11-02T13:00:00Z');
        foreach ([['indexedUnavailableFiles' => 1], ['indexedAvailableFiles' => -1], ['missingCatalogFiles' => 2], ['missingCatalogFiles' => 0]] as $change) {
            $snapshot = $this->snapshot();
            $snapshot['libraries']['music'] = array_replace($snapshot['libraries']['music'], $change);
            try {
                $sampler->parse(json_encode($snapshot, JSON_THROW_ON_ERROR));
                self::fail('Contradictory availability evidence accepted');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('snapshot', $error->getMessage());
            }
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $libraries = [];
        foreach (self::LIBRARIES as $role => $id) {
            $libraries[$role] = ['id' => $id, 'exists' => true, 'indexedFiles' => 1, 'indexedAvailableFiles' => 1, 'indexedUnavailableFiles' => 0, 'songs' => 0, 'albums' => 0, 'movies' => 0, 'videos' => 0, 'songsWithMbid' => 0, 'albumsWithMbid' => 0, 'moviesWithTmdbId' => 0, 'missingCatalogFiles' => 1, 'missingCatalogSamples' => ['/media/unimported.mkv']];
        }
        return ['version' => 1, 'observedAt' => '2026-11-02T13:01:00+00:00', 'from' => '2026-11-02T13:00:00.000000+00:00', 'libraries' => $libraries, 'jobs' => ['count' => 0, 'groupCount' => 0, 'groups' => [], 'failureSamples' => []], 'outbox' => ['pending' => 0, 'dead' => 0, 'maxAttempts' => 0], 'schedules' => [], 'connections' => [], 'database' => ['temp_files' => 0, 'temp_bytes' => 0, 'deadlocks' => 0, 'stats_reset' => null], 'vacuumTables' => [], 'vacuumProgress' => [], 'settings' => ['max_connections' => ['setting' => '100']]];
    }
}
