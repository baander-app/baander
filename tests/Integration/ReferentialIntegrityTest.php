<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Infrastructure\Doctrine\Repository\PartyMemberRepository;
use App\Party\Infrastructure\Doctrine\Repository\SyncedPartySessionRepository;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261006280000;
use DoctrineMigrations\Version20261006300000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Every column that refers to another row has a foreign key and an index that leads with it.
 *
 * Reads the catalog of the fully migrated disposable PostgreSQL database. A reference column
 * is one named `*_id` or `*_uuid` (other than the row's own `public_id`), or one listed in
 * SEMANTIC_REFERENCES.
 */
final class ReferentialIntegrityTest extends TestCase
{
    use OwnershipPersistenceHarness;

    /** References whose name does not end in `_id`. */
    private const SEMANTIC_REFERENCES = ['password_reset_tokens.email'];

    /** Reference-like columns without a foreign key, and why. */
    private const UNCONSTRAINED = [
        'albums.discogs_id' => 'external Discogs identifier',
        'albums.spotify_id' => 'external Spotify identifier',
        'artists.discogs_id' => 'external Discogs identifier',
        'artists.spotify_id' => 'external Spotify identifier',
        'devices.device_id' => 'client-generated device identifier, unique per user',
        'domain_event_outbox_delivery.notification_id' => 'delivery deduplication key that outlives the notification',
        'eq_device_profiles.device_id' => 'client-reported device label',
        'job_monitors.job_id' => 'Messenger message identifier',
        'job_monitors.job_uuid' => 'Messenger message identifier',
        'listening_sessions.active_device_id' => 'client device identifier; claiming a session does not require a registered device',
        'lyrics.lrclib_id' => 'external LRCLIB identifier',
        'movie_collections.tmdb_collection_id' => 'external TMDB identifier',
        'movies.imdb_id' => 'external IMDb identifier',
        'movies.tmdb_collection_id' => 'external TMDB identifier; movie_collections is not populated',
        'movies.tmdb_id' => 'external TMDB identifier',
        'oauth_access_tokens.chain_id' => 'token chain identifier with no chain table',
        'oauth_access_tokens.token_id' => 'the token\'s own OAuth identifier',
        'oauth_refresh_tokens.chain_id' => 'token chain identifier with no chain table',
        'oauth_refresh_tokens.token_id' => 'the token\'s own OAuth identifier',
        'oauth_token_metadata.session_id' => 'client-reported session identifier',
        'pairing_sessions.server_public_id' => 'copy of server_instances.public_id; server_id carries the foreign key',
        'party_members.audio_profile_id' => 'identifier within the video\'s probe data',
        'party_members.subtitle_track_id' => 'identifier within the video\'s probe data',
        'passkeys.credential_id' => 'the credential\'s own WebAuthn identifier',
        'password_reset_tokens.email' => 'pending decision: a token must not survive an email change or account deletion',
        'radio_stations.external_id' => 'external station directory identifier',
        'recommendations.source_id' => 'polymorphic with source_type',
        'recommendations.target_id' => 'polymorphic with target_type',
        'scheduler_occurrence_executions.attempt_id' => 'the attempt\'s own identifier',
        'scheduler_occurrence_executions.deployment_boot_id' => 'worker boot identifier with no table',
        'scheduler_occurrences.job_id' => 'occurrences keep their history after the schedule is deleted (Version20261002230000)',
        'songs.discogs_id' => 'external Discogs identifier',
        'songs.spotify_id' => 'external Spotify identifier',
        'user_favorites.entity_public_id' => 'polymorphic with entity_type',
        'webhook_delivery_logs.notification_id' => 'delivery history that outlives the notification',
        'worker_deployment_containers.boot_id' => 'worker boot identifier with no table',
        'worker_deployment_containers.container_id' => 'Docker container identifier',
        'worker_deployment_containers.daemon_id' => 'Docker daemon identifier',
        'worker_deployment_creations.boot_id' => 'worker boot identifier with no table',
        'worker_deployment_creations.daemon_id' => 'Docker daemon identifier',
        'worker_deployment_leases.owner_boot_id' => 'worker boot identifier with no table',
        'worker_deployment_retirements.boot_id' => 'worker boot identifier with no table',
        'worker_deployment_retirements.container_id' => 'Docker container identifier',
        'worker_deployment_retirements.daemon_id' => 'Docker daemon identifier',
    ];

    /** Foreign keys whose rows are found without an index that leads with all their columns, and why. */
    private const UNINDEXED = [
        'fk_scheduler_occurrence_executions_occurrence_id_job_id' => 'the primary key on occurrence_id finds the single referencing row',
    ];

    /** ON DELETE rules chosen by the referential integrity audit (Version20261006280000). */
    private const RULES = [
        'fk_pairing_sessions_server_id' => 'CASCADE',
        'fk_party_events_session_id' => 'CASCADE',
        'fk_party_events_user_id' => 'CASCADE',
        'fk_radio_sessions_active_station_id' => 'SET NULL',
        'fk_recommendation_jobs_original_job_id' => 'SET NULL',
        'fk_recommendation_jobs_user_id' => 'SET NULL',
        'fk_starred_stations_station_id' => 'CASCADE',
        'fk_transcode_jobs_video_id' => 'CASCADE',
        'fk_transcode_sessions_video_id' => 'CASCADE',
        'fk_user_favorites_user_id' => 'CASCADE',
        'fk_user_theme_moods_user_id' => 'CASCADE',
    ];

    /** Deleting a user deletes their access tokens (Version20261006300000). */
    private const OAUTH_RULES = [
        'fk_oauth_access_tokens_user_id' => 'CASCADE',
    ];

    /** ON DELETE rules for a party's video and transcode job (Version20261006310000). */
    private const PARTY_MEDIA_RULES = [
        'fk_party_sessions_transcode_job_id' => 'SET NULL',
        'fk_party_sessions_video_id' => 'CASCADE',
    ];

    public function testEveryReferenceColumnHasAForeignKeyOrADocumentedReason(): void
    {
        $columns = $this->manager->getConnection()->fetchAllKeyValue(<<<'SQL'
            SELECT t.relname || '.' || a.attname,
                   EXISTS (SELECT 1 FROM pg_constraint c
                            WHERE c.contype = 'f' AND c.conrelid = t.oid AND a.attnum = ANY (c.conkey))::int
              FROM pg_attribute a
              JOIN pg_class t ON t.oid = a.attrelid
             WHERE t.relnamespace = current_schema()::regnamespace AND t.relkind = 'r'
               AND a.attnum > 0 AND NOT a.attisdropped
               AND t.relname <> 'doctrine_migration_versions'
             ORDER BY 1
            SQL);

        $violations = [];
        foreach ($columns as $column => $constrained) {
            [, $name] = explode('.', $column);
            $reference = (str_ends_with($name, '_id') && $name !== 'public_id') || str_ends_with($name, '_uuid')
                || in_array($column, self::SEMANTIC_REFERENCES, true);
            if (!$reference) {
                continue;
            }
            if ((bool) $constrained === isset(self::UNCONSTRAINED[$column])) {
                $violations[] = $column . ($constrained ? ': has a foreign key but is listed as unconstrained' : ': no foreign key');
            }
        }

        foreach (array_keys(self::UNCONSTRAINED) as $column) {
            if (!array_key_exists($column, $columns)) {
                $violations[] = $column . ': listed as unconstrained but does not exist';
            }
        }

        self::assertSame([], $violations);
    }

    public function testEveryForeignKeyHasAnIndexLeadingWithItsColumns(): void
    {
        $unindexed = $this->manager->getConnection()->fetchFirstColumn(<<<'SQL'
            SELECT c.conname
              FROM pg_constraint c
              JOIN pg_class t ON t.oid = c.conrelid
             WHERE c.contype = 'f' AND t.relnamespace = current_schema()::regnamespace
               AND NOT EXISTS (
                   SELECT 1 FROM pg_index i
                    WHERE i.indrelid = c.conrelid AND i.indpred IS NULL
                      AND i.indnkeyatts >= cardinality(c.conkey)
                      AND (i.indkey::int2[])[0:cardinality(c.conkey) - 1] @> c.conkey
                      AND (i.indkey::int2[])[0:cardinality(c.conkey) - 1] <@ c.conkey)
             ORDER BY 1
            SQL);

        self::assertSame(array_keys(self::UNINDEXED), $unindexed);
    }

    public function testForeignKeysChosenByTheAuditHaveTheirDeleteRules(): void
    {
        $expected = [...self::RULES, ...self::OAUTH_RULES, ...self::PARTY_MEDIA_RULES];
        ksort($expected);
        $rules = $this->manager->getConnection()->fetchAllKeyValue(<<<'SQL'
            SELECT conname, CASE confdeltype WHEN 'c' THEN 'CASCADE' WHEN 'n' THEN 'SET NULL'
                   WHEN 'r' THEN 'RESTRICT' WHEN 'a' THEN 'NO ACTION' ELSE confdeltype::text END
              FROM pg_constraint
             WHERE contype = 'f' AND conname IN (:names)
             ORDER BY 1
            SQL, ['names' => array_keys($expected)], ['names' => ArrayParameterType::STRING]);

        ksort($rules);
        self::assertSame($expected, $rules);
    }

    public function testDeletingAUserRemovesTheirFavoritesAndThemeMood(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        foreach ([$owner, $other] as $user) {
            $this->insert('user_favorites', [
                'user_id' => $user->toString(),
                'public_id' => (new PublicId())->toString(),
                'entity_type' => 'album',
                'entity_public_id' => (new PublicId())->toString(),
                'created_at' => '2026-10-06 12:00:00+00',
                'updated_at' => '2026-10-06 12:00:00+00',
            ]);
            $this->insert('user_theme_moods', [
                'user_id' => $user->toString(),
                'created_at' => '2026-10-06 12:00:00',
                'updated_at' => '2026-10-06 12:00:00',
            ]);
        }

        $this->deleteUser($owner);

        foreach (['user_favorites', 'user_theme_moods'] as $table) {
            self::assertSame(0, $this->countOwnedRows($table, 'user_id', $owner), $table);
            self::assertSame(1, $this->countOwnedRows($table, 'user_id', $other), $table);
        }
    }

    public function testDeletingAUserRemovesTheirAccessTokensWithTheirRefreshTokensAndMetadata(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $client = $this->createClient();
        $tokens = [];
        foreach ([$owner, $other] as $user) {
            $tokens[] = $this->createAccessToken($client, $user);
        }

        $this->deleteUser($owner);

        self::assertSame(0, $this->countOwnedRows('oauth_access_tokens', 'user_id', $owner));
        self::assertSame(0, $this->countOwnedRows('oauth_refresh_tokens', 'access_token_id', $tokens[0]));
        self::assertSame(0, $this->countOwnedRows('oauth_token_metadata', 'token_id', $tokens[0]));
        self::assertSame(1, $this->countOwnedRows('oauth_access_tokens', 'user_id', $other));
        self::assertSame(1, $this->countOwnedRows('oauth_refresh_tokens', 'access_token_id', $tokens[1]));
        self::assertSame(1, $this->countOwnedRows('oauth_token_metadata', 'token_id', $tokens[1]));
    }

    public function testDeletingAVideoRemovesItsTranscodeJobsAndTheirSessions(): void
    {
        $viewer = $this->createUser();
        $deleted = $this->createVideo();
        $kept = $this->createVideo();
        $jobs = [];
        foreach ([$deleted, $kept] as $video) {
            $jobs[] = $job = $this->insert('transcode_jobs', [
                'video_id' => $video->toString(),
                'public_id' => (new PublicId())->toString(),
                'quality_tier_name' => '1080p',
                'created_at' => '2026-10-06 12:00:00+00',
                'updated_at' => '2026-10-06 12:00:00+00',
            ]);
            $this->insert('transcode_sessions', [
                'user_id' => $viewer->toString(),
                'job_id' => $job->toString(),
                'video_id' => $video->toString(),
                'public_id' => (new PublicId())->toString(),
                'created_at' => '2026-10-06 12:00:00+00',
                'updated_at' => '2026-10-06 12:00:00+00',
            ]);
        }

        $this->manager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $deleted->toString()]);

        self::assertSame(0, $this->countOwnedRows('transcode_jobs', 'video_id', $deleted));
        self::assertSame(0, $this->countOwnedRows('transcode_sessions', 'video_id', $deleted));
        self::assertSame(0, $this->countOwnedRows('transcode_sessions', 'job_id', $jobs[0]));
        self::assertSame(1, $this->countOwnedRows('transcode_jobs', 'video_id', $kept));
        self::assertSame(1, $this->countOwnedRows('transcode_sessions', 'job_id', $jobs[1]));
    }

    public function testDeletingAVideoRemovesItsPartiesAndTheirMembers(): void
    {
        $host = $this->createUser();
        $guest = $this->createUser();
        $deleted = $this->createVideo();
        $kept = $this->createVideo();
        $sessions = new SyncedPartySessionRepository($this->manager);
        $members = new PartyMemberRepository($this->manager);
        $parties = [];
        foreach ([$deleted, $kept] as $video) {
            $parties[] = $party = SyncedPartySession::create($host, $video, $this->createTranscodeJob($video));
            $sessions->save($party);
            $members->save(PartyMember::create($guest, $party->getId()));
        }
        $this->manager->clear();

        $this->manager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $deleted->toString()]);

        self::assertSame(0, $this->countOwnedRows('party_sessions', 'video_id', $deleted));
        self::assertSame(0, $this->countOwnedRows('party_members', 'session_id', $parties[0]->getId()));
        self::assertSame(1, $this->countOwnedRows('party_sessions', 'video_id', $kept));
        self::assertSame(1, $this->countOwnedRows('party_members', 'session_id', $parties[1]->getId()));
    }

    public function testDeletingATranscodeJobLeavesItsPartiesWithoutAJob(): void
    {
        $host = $this->createUser();
        $video = $this->createVideo();
        $deleted = $this->createTranscodeJob($video, '720p');
        $kept = $this->createTranscodeJob($video, '1080p');
        $sessions = new SyncedPartySessionRepository($this->manager);
        $withDeletedJob = SyncedPartySession::create($host, $video, $deleted);
        $withKeptJob = SyncedPartySession::create($host, $video, $kept);
        $sessions->save($withDeletedJob);
        $sessions->save($withKeptJob);
        $this->manager->clear();

        $this->manager->getConnection()->executeStatement('DELETE FROM transcode_jobs WHERE id = :id', ['id' => $deleted->toString()]);

        $reloaded = $sessions->findByUuid($withDeletedJob->getId());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isActive());
        self::assertNull($reloaded->getTranscodeJobId());
        self::assertTrue($sessions->findByUuid($withKeptJob->getId())?->getTranscodeJobId()?->equals($kept));
    }

    public function testDeletingAStarredStationRemovesItsStarsAndStopsPointingRadioSessionsAtIt(): void
    {
        $listener = $this->createUser();
        $source = $this->insert('radio_sources', [
            'name' => 'Integrity source',
            'type' => 'radio-browser',
            'sync_url' => 'https://radio.baander.app',
            'created_at' => '2026-10-06 12:00:00+00',
            'updated_at' => '2026-10-06 12:00:00+00',
        ]);
        $stations = [];
        foreach (['deleted', 'kept'] as $name) {
            $stations[] = $station = $this->insert('radio_stations', [
                'source_id' => $source->toString(),
                'external_id' => 'integrity-' . $name,
                'name' => 'Station ' . $name,
                'country' => 'DK',
                'created_at' => '2026-10-06 12:00:00+00',
                'updated_at' => '2026-10-06 12:00:00+00',
            ]);
            $this->insert('starred_stations', [
                'user_id' => $listener->toString(),
                'station_id' => $station->toString(),
                'starred_at' => '2026-10-06 12:00:00+00',
            ]);
        }
        $session = $this->insert('radio_sessions', [
            'user_id' => $listener->toString(),
            'active_station_id' => $stations[0]->toString(),
            'created_at' => '2026-10-06 12:00:00+00',
            'updated_at' => '2026-10-06 12:00:00+00',
        ]);

        $connection = $this->manager->getConnection();
        $connection->executeStatement('DELETE FROM radio_stations WHERE id = :id', ['id' => $stations[0]->toString()]);

        self::assertSame(0, $this->countOwnedRows('starred_stations', 'station_id', $stations[0]));
        self::assertSame(1, $this->countOwnedRows('starred_stations', 'station_id', $stations[1]));
        self::assertSame(
            [['user_id' => $listener->toString(), 'active_station_id' => null]],
            $connection->fetchAllAssociative('SELECT user_id, active_station_id FROM radio_sessions WHERE id = :id', ['id' => $session->toString()]),
        );
    }

    public function testMigrationRemovesOrphansBeforeConstrainingAndRoundTrips(): void
    {
        $connection = $this->manager->getConnection();
        $latest = $this->constraintsAndIndexes();

        // Version20261006300000 dropped columns and tables Version20261006280000 constrains; restore them first.
        $this->runMigration('down', Version20261006300000::class);
        $this->runMigration('down');
        $restored = $this->constraintsAndIndexes();
        foreach (array_keys(self::RULES) as $name) {
            if (in_array($name, ['fk_radio_sessions_active_station_id', 'fk_starred_stations_station_id'], true)) {
                self::assertStringNotContainsString('ON DELETE', $restored[$name], $name);
            } else {
                self::assertArrayNotHasKey($name, $restored);
            }
        }
        self::assertArrayNotHasKey('idx_starred_stations_station_id', $restored);

        // Rows the earlier schema allowed: references to rows that no longer exist.
        $owner = $this->createUser();
        $missing = Uuid::generate()->toString();
        $favorite = static fn (string $user): array => [
            'user_id' => $user,
            'public_id' => (new PublicId())->toString(),
            'entity_type' => 'album',
            'entity_public_id' => (new PublicId())->toString(),
            'created_at' => '2026-10-06 12:00:00+00',
            'updated_at' => '2026-10-06 12:00:00+00',
        ];
        $keptFavorite = $this->insert('user_favorites', $favorite($owner->toString()));
        $orphanFavorite = $this->insert('user_favorites', $favorite($missing));
        $orphanJob = $this->insert('transcode_jobs', [
            'video_id' => $missing,
            'public_id' => (new PublicId())->toString(),
            'quality_tier_name' => '1080p',
            'created_at' => '2026-10-06 12:00:00+00',
            'updated_at' => '2026-10-06 12:00:00+00',
        ]);
        $requeued = $this->insert('recommendation_jobs', [
            'user_id' => $missing,
            'original_job_id' => $missing,
            'public_id' => (new PublicId())->toString(),
            'is_full' => 'true',
            'status' => 'failed',
            'created_at' => '2026-10-06 12:00:00',
            'updated_at' => '2026-10-06 12:00:00',
        ]);

        $this->runMigration('up');

        // Rows Version20261006300000 removes: a personal access client with a token, and a token whose user was deleted.
        $personal = $this->createClient(['personal_access_client' => 'true', 'user_id' => $owner->toString()]);
        $personalToken = $this->createAccessToken($personal, $owner);
        $tokenOwner = $this->createUser();
        $orphanToken = $this->createAccessToken($this->createClient(), $tokenOwner);
        $this->deleteUser($tokenOwner);
        self::assertSame(
            [['user_id' => null]],
            $connection->fetchAllAssociative('SELECT user_id FROM oauth_access_tokens WHERE id = :id', ['id' => $orphanToken->toString()]),
        );

        $this->runMigration('up', Version20261006300000::class);

        self::assertSame($latest, $this->constraintsAndIndexes());
        self::assertSame(0, $this->countOwnedRows('oauth_clients', 'id', $personal));
        foreach ([$personalToken, $orphanToken] as $token) {
            self::assertSame(0, $this->countOwnedRows('oauth_access_tokens', 'id', $token));
            self::assertSame(0, $this->countOwnedRows('oauth_refresh_tokens', 'access_token_id', $token));
        }
        self::assertSame(
            [$keptFavorite->toString()],
            $connection->fetchFirstColumn('SELECT id FROM user_favorites WHERE id IN (:kept, :orphan)', [
                'kept' => $keptFavorite->toString(),
                'orphan' => $orphanFavorite->toString(),
            ]),
        );
        self::assertSame(0, $this->countOwnedRows('transcode_jobs', 'id', $orphanJob));
        self::assertSame(
            [['user_id' => null, 'original_job_id' => null]],
            $connection->fetchAllAssociative('SELECT user_id, original_job_id FROM recommendation_jobs WHERE id = :id', ['id' => $requeued->toString()]),
        );
    }

    /** @param class-string<Version20261006280000|Version20261006300000> $class */
    private function runMigration(string $direction, string $class = Version20261006280000::class): void
    {
        require_once dirname(__DIR__, 2) . '/migrations/' . substr($class, strrpos($class, '\\') + 1) . '.php';
        $connection = $this->manager->getConnection();
        $migration = new $class($connection, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return array<string, string> foreign key or index name => definition */
    private function constraintsAndIndexes(): array
    {
        return $this->manager->getConnection()->fetchAllKeyValue(<<<'SQL'
            SELECT c.conname, pg_get_constraintdef(c.oid)
              FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid
             WHERE c.contype = 'f' AND t.relnamespace = current_schema()::regnamespace
            UNION ALL
            SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema()
             ORDER BY 1
            SQL);
    }

    /** @param array<string, string> $columns */
    private function createClient(array $columns = []): Uuid
    {
        return $this->insert('oauth_clients', [
            'public_id' => (new PublicId())->toString(),
            'name' => 'Integrity client',
            'redirect' => '["https://app.baander.app/callback"]',
            'created_at' => '2026-10-07 12:00:00+00',
            'updated_at' => '2026-10-07 12:00:00+00',
            ...$columns,
        ]);
    }

    /** An access token with one refresh token and its metadata. */
    private function createAccessToken(Uuid $client, Uuid $user): Uuid
    {
        $times = ['created_at' => '2026-10-07 12:00:00+00', 'updated_at' => '2026-10-07 12:00:00+00'];
        $token = $this->insert('oauth_access_tokens', [
            'token_id' => bin2hex(random_bytes(16)),
            'client_id' => $client->toString(),
            'user_id' => $user->toString(),
            ...$times,
        ]);
        $this->insert('oauth_refresh_tokens', ['token_id' => bin2hex(random_bytes(16)), 'access_token_id' => $token->toString(), ...$times]);
        $this->insert('oauth_token_metadata', ['token_id' => $token->toString(), ...$times]);

        return $token;
    }

    private function createTranscodeJob(Uuid $video, string $tier = '1080p'): Uuid
    {
        return $this->insert('transcode_jobs', [
            'video_id' => $video->toString(),
            'public_id' => (new PublicId())->toString(),
            'quality_tier_name' => $tier,
            'created_at' => '2026-10-07 12:00:00+00',
            'updated_at' => '2026-10-07 12:00:00+00',
        ]);
    }

    /** @param array<string, string> $row */
    private function insert(string $table, array $row): Uuid
    {
        $id = Uuid::generate();
        $this->manager->getConnection()->insert($table, ['id' => $id->toString(), ...$row]);

        return $id;
    }
}
