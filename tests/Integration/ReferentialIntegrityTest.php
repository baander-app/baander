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
use PHPUnit\Framework\TestCase;

/**
 * Every column that refers to another row has a foreign key and an index that leads with it.
 *
 * Reads the catalog of the fully migrated disposable PostgreSQL database. A reference column
 * is one named `*_id` or `*_uuid` (other than the row's own `public_id`).
 */
final class ReferentialIntegrityTest extends TestCase
{
    use OwnershipPersistenceHarness;

    /** Reference-like columns without a foreign key, and why. */
    private const UNCONSTRAINED = [
        'audio_rendition_runtime.worker_boot_id' => 'validated supervisor boot token; mutable deployment leases do not model individual worker launches',
        'audio_rendition_runtime.worker_id' => 'worker role label within a launch, not a row identifier',
        'albums.discogs_id' => 'external Discogs identifier',
        'albums.spotify_id' => 'external Spotify identifier',
        'artists.discogs_id' => 'external Discogs identifier',
        'artists.spotify_id' => 'external Spotify identifier',
        'devices.device_id' => 'client-generated device identifier, unique per user',
        'domain_event_outbox_delivery.notification_id' => 'delivery deduplication key that outlives the notification',
        'eq_device_profiles.device_id' => 'client-reported device label',
        'job_monitors.job_id' => 'Messenger message identifier',
        'job_monitors.job_uuid' => 'Messenger message identifier',
        'libraries.claim_id' => 'the library claim\'s own identifier, which the scan or delete holding the claim carries',
        'listening_sessions.active_device_id' => 'browser device token scoped to its user, distinct from the device primary key',
        'lyrics.lrclib_id' => 'external LRCLIB identifier',
        'movie_collections.tmdb_collection_id' => 'external TMDB identifier',
        'movies.imdb_id' => 'external IMDb identifier',
        'movies.tmdb_collection_id' => 'external TMDB identifier; movie_collections is not populated',
        'movies.tmdb_id' => 'external TMDB identifier',
        'oauth_access_tokens.chain_id' => 'token chain identifier with no chain table',
        'oauth_access_tokens.token_id' => 'the token\'s own OAuth identifier',
        'oauth_auth_codes.code_id' => 'the code\'s own OAuth identifier',
        'oauth_refresh_tokens.chain_id' => 'token chain identifier with no chain table',
        'oauth_refresh_tokens.token_id' => 'the token\'s own OAuth identifier',
        'oauth_token_metadata.session_id' => 'client-reported session identifier',
        'pairing_sessions.server_public_id' => 'copy of server_instances.public_id; server_id carries the foreign key',
        'party_members.audio_profile_id' => 'identifier within the video\'s probe data',
        'party_members.subtitle_track_id' => 'identifier within the video\'s probe data',
        'passkeys.credential_id' => 'the credential\'s own WebAuthn identifier',
        'radio_stations.external_id' => 'external station directory identifier',
        'recommendations.source_id' => 'polymorphic with source_type',
        'recommendations.target_id' => 'polymorphic with target_type',
        'scheduler_occurrence_executions.attempt_id' => 'the attempt\'s own identifier',
        'scheduler_occurrence_executions.deployment_boot_id' => 'worker boot identifier with no table',
        'scheduler_occurrences.job_id' => 'occurrences keep their history after the schedule is deleted',
        'scheduler_occurrences.job_public_id' => 'immutable public job identity snapshot retained after schedule deletion',
        'songs.discogs_id' => 'external Discogs identifier',
        'songs.spotify_id' => 'external Spotify identifier',
        'transcode_control_receipts.request_id' => 'caller-generated control replay identity scoped to its playback session',
        'transcode_runtime_state.request_id' => 'caller-generated admission replay identity, not a referenced row',
        'transcode_runtime_state.worker_boot_id' => 'validated supervisor boot token; mutable deployment leases do not model individual worker launches',
        'transcode_runtime_state.worker_id' => 'worker role label within a launch, not a row identifier',
        'user_favorites.entity_public_id' => 'polymorphic with entity_type',
        'webhook_delivery_logs.notification_id' => 'delivery history that outlives the notification',
        'worker_deployment_boots.boot_id' => 'boot journal identity at the root of the deployment lifecycle',
        'worker_deployment_boots.daemon_id' => 'external Docker daemon identifier recorded by the boot journal',
        'worker_deployment_containers.container_id' => 'Docker container identifier',
        'worker_deployment_leases.owner_boot_id' => 'lease owner token retained independently of boot journal references; admission checks the matching boot',
    ];

    /** Foreign keys whose rows are found without an index that leads with all their columns, and why. */
    private const UNINDEXED = [
        'fk_scheduler_occurrence_executions_occurrence_id_job_id' => 'the primary key on occurrence_id finds the single referencing row',
        'fk_worker_deployment_creations_namespace_boot_id_daemon_id' => 'the namespace and boot primary key prefix finds at most the two checked control and execution units',
        'fk_worker_deployment_retirements_binding' => 'the namespace, boot and unit primary key finds the single referencing row before checking daemon and container identity',
    ];

    /** ON DELETE rules chosen by the referential integrity audit. */
    private const RULES = [
        'fk_oauth_clients_user_id' => 'CASCADE',
        'fk_pairing_sessions_server_id' => 'CASCADE',
        'fk_party_events_session_id' => 'CASCADE',
        'fk_party_events_user_id' => 'CASCADE',
        'fk_radio_sessions_active_station_id' => 'SET NULL',
        'fk_recommendation_jobs_original_job_id' => 'SET NULL',
        'fk_recommendation_jobs_user_id' => 'SET NULL',
        'fk_starred_stations_station_id' => 'CASCADE',
        'fk_transcode_jobs_video_id' => 'CASCADE',
        'fk_transcode_jobs_owner_user_id' => 'CASCADE',
        'fk_transcode_control_receipts_session_id' => 'CASCADE',
        'fk_transcode_sessions_video_id' => 'CASCADE',
        'fk_user_favorites_user_id' => 'CASCADE',
        'fk_user_theme_moods_user_id' => 'CASCADE',
    ];

    /** Deleting a user deletes their OAuth tokens and codes. */
    private const OAUTH_RULES = [
        'fk_oauth_access_tokens_user_id' => 'CASCADE',
        'fk_oauth_auth_codes_user_id' => 'CASCADE',
        'fk_oauth_device_codes_user_id' => 'CASCADE',
    ];

    /** ON DELETE rules for a party's video and transcode job. */
    private const PARTY_MEDIA_RULES = [
        'fk_party_sessions_transcode_job_id' => 'SET NULL',
        'fk_party_sessions_video_id' => 'CASCADE',
    ];

    /** A reset token ends with its user. */
    private const PASSWORD_RESET_RULES = [
        'fk_password_reset_tokens_user_id' => 'CASCADE',
    ];

    /** A verification token ends with its user. */
    private const EMAIL_VERIFICATION_RULES = [
        'fk_email_verification_tokens_user_id' => 'CASCADE',
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
            $reference = (str_ends_with($name, '_id') && $name !== 'public_id') || str_ends_with($name, '_uuid');
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
        $expected = [...self::RULES, ...self::OAUTH_RULES, ...self::PARTY_MEDIA_RULES, ...self::PASSWORD_RESET_RULES, ...self::EMAIL_VERIFICATION_RULES];
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

    public function testDeletingAUserRemovesTheirFavoritesThemeMoodAndTokens(): void
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
            // Keyed by user_id, so the table has no id column for insert() to fill.
            $this->manager->getConnection()->insert('password_reset_tokens', [
                'user_id' => $user->toString(),
                'token_hash' => hash('sha256', $user->toString()),
                'created_at' => '2026-10-06 12:00:00+00',
                'expires_at' => '2026-10-06 13:00:00+00',
            ]);
            $this->manager->getConnection()->insert('email_verification_tokens', [
                'user_id' => $user->toString(),
                'email' => 'verify-' . $user->toString() . '@baander.app',
                'token_hash' => hash('sha256', 'verify-' . $user->toString()),
                'created_at' => '2026-10-06 12:00:00+00',
                'expires_at' => '2026-10-07 12:00:00+00',
            ]);
        }

        $this->deleteUser($owner);

        foreach (['user_favorites', 'user_theme_moods', 'password_reset_tokens', 'email_verification_tokens'] as $table) {
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

    public function testDeletingAUserRemovesTheirAuthCodesAndApprovedDeviceCodes(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $client = $this->createClient();
        foreach ([$owner, $other] as $user) {
            $this->createAuthCode($client, $user);
            $this->createDeviceCode($client, $user);
        }
        // Device codes are bound to a user only on approval.
        $pending = $this->createDeviceCode($client);
        $denied = $this->createDeviceCode($client, denied: true);

        $this->deleteUser($owner);

        foreach (['oauth_auth_codes', 'oauth_device_codes'] as $table) {
            self::assertSame(0, $this->countOwnedRows($table, 'user_id', $owner), $table);
            self::assertSame(1, $this->countOwnedRows($table, 'user_id', $other), $table);
        }
        foreach ([$pending, $denied] as $code) {
            self::assertSame(1, $this->countOwnedRows('oauth_device_codes', 'id', $code));
        }
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
            'public_id' => (new PublicId())->toString(),
            'name' => 'Integrity source',
            'type' => 'radio-browser',
            'sync_url' => 'https://radio.baander.app',
            'created_at' => '2026-10-06 12:00:00+00',
            'updated_at' => '2026-10-06 12:00:00+00',
        ]);
        $stations = [];
        foreach (['deleted', 'kept'] as $name) {
            $stations[] = $station = $this->insert('radio_stations', [
                'public_id' => (new PublicId())->toString(),
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

    private function createAuthCode(Uuid $client, Uuid $user): Uuid
    {
        return $this->insert('oauth_auth_codes', [
            'code_id' => bin2hex(random_bytes(16)),
            'client_id' => $client->toString(),
            'user_id' => $user->toString(),
            'redirect_uri' => 'https://app.baander.app/callback',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'created_at' => '2026-10-07 12:00:00+00',
            'updated_at' => '2026-10-07 12:00:00+00',
        ]);
    }

    /** A device code approved by $user, or a pending or denied one without a user. */
    private function createDeviceCode(Uuid $client, ?Uuid $user = null, bool $denied = false): Uuid
    {
        return $this->insert('oauth_device_codes', [
            'device_code' => bin2hex(random_bytes(16)),
            'user_code' => strtoupper(bin2hex(random_bytes(4))),
            'client_id' => $client->toString(),
            'verification_uri' => 'https://app.baander.app/device',
            'user_id' => $user?->toString(),
            'approved' => $user !== null ? 'true' : 'false',
            'denied' => $denied ? 'true' : 'false',
            'created_at' => '2026-10-07 12:00:00+00',
            'updated_at' => '2026-10-07 12:00:00+00',
        ]);
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
