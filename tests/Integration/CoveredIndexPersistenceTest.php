<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261006260000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Version20261006260000 drops twelve B-tree indexes whose columns lead a wider index on the same table.
 */
final class CoveredIndexPersistenceTest extends TestCase
{
    use OwnershipPersistenceHarness;

    private const ID = '00000000-0000-4000-8000-000000000000';

    /** Dropped index => [table, lookup predicate on its columns, index that serves the lookup instead]. */
    private const COVERED = [
        'idx_artist_album_artist_id' => ['artist_album', 'artist_id = :id', 'uniq_artist_album_artist_id_album_id_role'],
        'idx_artist_song_artist_id' => ['artist_song', 'artist_id = :id', 'uniq_artist_song_artist_id_song_id_role'],
        'idx_genre_album_genre_id' => ['genre_album', 'genre_id = :id', 'uniq_genre_album_genre_id_album_id'],
        'idx_genre_movie_genre_id' => ['genre_movie', 'genre_id = :id', 'uniq_genre_movie_genre_id_movie_id'],
        'idx_job_monitors_status' => ['job_monitors', 'status = :text', 'idx_job_monitors_status_created_at'],
        'idx_party_members_user_id' => ['party_members', 'user_id = :id', 'uniq_party_members_user_id_session_id'],
        'idx_playlist_song_playlist_id' => ['playlist_song', 'playlist_id = :id', 'uniq_playlist_song_playlist_id_song_id'],
        'idx_recommendations_source_type_source_id' => ['recommendations', 'source_type = :text AND source_id = :text', 'uniq_recommendations_source_target_name_user_id'],
        'idx_songs_title' => ['songs', 'title = :text', 'idx_songs_title_id'],
        'idx_starred_stations_user_id' => ['starred_stations', 'user_id = :id', 'uniq_starred_stations_user_id_station_id'],
        'idx_transcode_jobs_video_id' => ['transcode_jobs', 'video_id = :id', 'uniq_transcode_jobs_video_id_quality_tier_name'],
        'idx_user_favorites_user_id' => ['user_favorites', 'user_id = :id', 'uniq_user_favorites_user_id_entity_type_entity_public_id'],
    ];

    /** The definitions Version20261006260000::down() must restore. */
    private const DROPPED_DEFINITIONS = [
        'idx_artist_album_artist_id' => 'CREATE INDEX idx_artist_album_artist_id ON public.artist_album USING btree (artist_id)',
        'idx_artist_song_artist_id' => 'CREATE INDEX idx_artist_song_artist_id ON public.artist_song USING btree (artist_id)',
        'idx_genre_album_genre_id' => 'CREATE INDEX idx_genre_album_genre_id ON public.genre_album USING btree (genre_id)',
        'idx_genre_movie_genre_id' => 'CREATE INDEX idx_genre_movie_genre_id ON public.genre_movie USING btree (genre_id)',
        'idx_job_monitors_status' => 'CREATE INDEX idx_job_monitors_status ON public.job_monitors USING btree (status)',
        'idx_party_members_user_id' => 'CREATE INDEX idx_party_members_user_id ON public.party_members USING btree (user_id)',
        'idx_playlist_song_playlist_id' => 'CREATE INDEX idx_playlist_song_playlist_id ON public.playlist_song USING btree (playlist_id)',
        'idx_recommendations_source_type_source_id' => 'CREATE INDEX idx_recommendations_source_type_source_id ON public.recommendations USING btree (source_type, source_id)',
        'idx_songs_title' => 'CREATE INDEX idx_songs_title ON public.songs USING btree (title)',
        'idx_starred_stations_user_id' => 'CREATE INDEX idx_starred_stations_user_id ON public.starred_stations USING btree (user_id)',
        'idx_transcode_jobs_video_id' => 'CREATE INDEX idx_transcode_jobs_video_id ON public.transcode_jobs USING btree (video_id)',
        'idx_user_favorites_user_id' => 'CREATE INDEX idx_user_favorites_user_id ON public.user_favorites USING btree (user_id)',
    ];

    public function testSchemaComparisonIsCleanForTheAffectedTables(): void
    {
        $this->assertSchemaComparisonIsClean(array_values(array_unique(array_column(self::COVERED, 0))));
    }

    public function testTheWiderIndexesAloneServeTheDroppedIndexLookups(): void
    {
        $connection = $this->manager->getConnection();
        $indexes = $this->indexes();
        foreach (self::COVERED as $dropped => [$table, $predicate, $covering]) {
            self::assertArrayNotHasKey($dropped, $indexes);
            self::assertArrayHasKey($covering, $indexes, $dropped);
        }

        // Equality on the dropped index's columns, as in owner lookups and foreign-key cascades, uses the wider index.
        // Analyzing first puts the tables in the state that once failed in the full suite: a near-empty table
        // with fresh statistics. PGroonga estimates a scan of its whole index at zero cost, so on such a table
        // the planner prefers an unqualified PGroonga bitmap or index-only scan with a filter over any B-tree
        // lookup. Only an index scan with an index condition shows that the wider index serves the predicate.
        foreach (array_unique(array_column(self::COVERED, 0)) as $table) {
            $connection->executeStatement('ANALYZE ' . $table);
        }
        $connection->executeStatement('SET LOCAL enable_seqscan = off');
        $connection->executeStatement('SET LOCAL enable_bitmapscan = off');
        $connection->executeStatement('SET LOCAL enable_indexonlyscan = off');
        foreach (self::COVERED as $dropped => [$table, $predicate, $covering]) {
            $parameters = array_filter(
                ['id' => self::ID, 'text' => 'covered-index-probe'],
                static fn (string $name): bool => str_contains($predicate, ':' . $name),
                ARRAY_FILTER_USE_KEY,
            );
            $plan = implode("\n", $connection->fetchFirstColumn(
                sprintf('EXPLAIN (COSTS OFF) SELECT 1 FROM %s WHERE %s', $table, $predicate),
                $parameters,
            ));
            self::assertStringContainsString('Index Scan using ' . $covering . ' on ' . $table, $plan, $dropped);
            self::assertStringContainsString('Index Cond:', $plan, $dropped);
        }
    }

    public function testMigrationRoundTripRestoresTheIdenticalDefinitions(): void
    {
        $connection = $this->manager->getConnection();
        $latest = $this->indexes();

        require_once dirname(__DIR__, 2) . '/migrations/Version20261006260000.php';
        foreach (['down', 'up'] as $direction) {
            $migration = new Version20261006260000($connection, new NullLogger());
            $migration->{$direction}(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }

            $expected = $direction === 'down' ? array_merge($latest, self::DROPPED_DEFINITIONS) : $latest;
            ksort($expected);
            self::assertSame($expected, $this->indexes(), $direction);
        }
    }

    /** @return array<string, string> index name => definition, for the affected tables */
    private function indexes(): array
    {
        $tables = array_values(array_unique(array_column(self::COVERED, 0)));

        $indexes = $this->manager->getConnection()->fetchAllKeyValue(
            'SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ANY (:tables::text[])',
            ['tables' => '{' . implode(',', $tables) . '}'],
        );
        ksort($indexes);

        return $indexes;
    }
}
