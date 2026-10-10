<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Operator note: stop the web server and the worker first. Every ALTER takes an ACCESS EXCLUSIVE
 * lock until the migration commits; the lock timeout makes the migration fail instead of queueing
 * behind a running process and blocking everything queued after it.
 */
final class Version20261010180000 extends AbstractMigration
{
    /**
     * timestamp(0) with time zone columns, from the catalog of a database at Version20261010110000.
     * Relaxing the precision keeps the stored values and does not rewrite the table.
     */
    private const array WHOLE_SECOND = [
        'albums' => ['created_at', 'updated_at'],
        'artists' => ['created_at', 'updated_at'],
        'audio_preferences' => ['created_at', 'updated_at'],
        'country_subscriptions' => ['created_at', 'last_synced_at'],
        'devices' => ['created_at', 'last_seen_at'],
        'domain_event_outbox' => ['created_at', 'dead_lettered_at', 'next_attempt_at', 'relayed_at'],
        'genres' => ['created_at', 'updated_at'],
        'images' => ['created_at', 'updated_at'],
        'layout_preferences' => ['created_at', 'updated_at'],
        'libraries' => ['created_at', 'last_scan', 'updated_at'],
        'library_file_index' => ['discovered_at'],
        'listening_sessions' => ['created_at', 'last_used_at', 'updated_at'],
        'login_blocks' => ['created_at'],
        'lyrics' => ['created_at', 'updated_at'],
        'media_activities' => ['created_at', 'last_played_at', 'updated_at'],
        'movies' => ['created_at', 'updated_at'],
        'notification_preferences' => ['created_at', 'updated_at'],
        'notifications' => ['created_at'],
        'oauth_access_tokens' => ['created_at', 'expires_at', 'last_refreshed_at', 'updated_at'],
        'oauth_auth_codes' => ['created_at', 'expires_at', 'updated_at'],
        'oauth_clients' => ['created_at', 'updated_at'],
        'oauth_device_codes' => ['consumed_at', 'created_at', 'expires_at', 'last_polled_at', 'updated_at'],
        'oauth_refresh_tokens' => ['created_at', 'expires_at', 'updated_at', 'used_at'],
        'oauth_scopes' => ['created_at', 'updated_at'],
        'oauth_token_metadata' => ['created_at', 'last_geo_notification_at', 'updated_at'],
        'pairing_sessions' => ['completed_at', 'created_at', 'expired_at', 'expires_at', 'updated_at'],
        'party_events' => ['occurred_at'],
        'party_members' => ['joined_at', 'last_sync_at', 'updated_at'],
        'party_sessions' => ['created_at', 'playback_started_at', 'updated_at'],
        'passkeys' => ['created_at', 'last_used_at', 'updated_at'],
        'player_preferences' => ['created_at', 'updated_at'],
        'playlist_collaborators' => ['created_at', 'updated_at'],
        'playlist_song' => ['created_at', 'updated_at'],
        'playlist_statistics' => ['created_at', 'updated_at'],
        'playlists' => ['created_at', 'updated_at'],
        'preference_history' => ['created_at'],
        'radio_sessions' => ['created_at', 'updated_at'],
        'radio_sources' => ['created_at', 'updated_at'],
        'radio_stations' => ['created_at', 'last_checked_at', 'updated_at'],
        'recommendations' => ['created_at', 'updated_at'],
        'scheduled_jobs' => ['created_at', 'last_failure_at', 'last_run_at', 'next_run_at', 'updated_at'],
        'server_instances' => ['created_at', 'last_heartbeat_at', 'updated_at'],
        'songs' => ['created_at', 'updated_at'],
        'starred_stations' => ['starred_at'],
        'third_party_credentials' => ['created_at', 'expires_at', 'updated_at'],
        'transcode_jobs' => ['created_at', 'updated_at'],
        'transcode_sessions' => ['created_at', 'updated_at'],
        'user_accent_colors' => ['created_at', 'updated_at'],
        'user_favorites' => ['created_at', 'updated_at'],
        'user_sidebar_configs' => ['created_at', 'updated_at'],
        'users' => ['created_at', 'email_verified_at', 'updated_at'],
        'videos' => ['created_at', 'updated_at'],
    ];

    /**
     * timestamp(0) without time zone columns, with their former default. Converting them rewrites
     * these five small tables. doctrine_migration_versions.executed_at stays zone-less: Doctrine
     * Migrations parses it as Y-m-d H:i:s and reads a timestamptz value as null.
     */
    private const array ZONE_LESS = [
        'eq_device_profiles' => ['created_at' => 'now()', 'updated_at' => 'now()'],
        'recommendation_jobs' => ['completed_at' => 'NULL', 'created_at' => null, 'started_at' => 'NULL', 'updated_at' => null],
        'user_theme_moods' => ['created_at' => null, 'updated_at' => null],
        'webhook_delivery_logs' => ['created_at' => null],
        'webhooks' => ['created_at' => null, 'updated_at' => null],
    ];

    public function getDescription(): string
    {
        return 'Store every instant as timestamptz with microseconds; zone-less values are UTC.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        foreach (self::WHOLE_SECOND as $table => $columns) {
            $this->alter($table, array_map(
                static fn (string $column): string => sprintf('ALTER COLUMN %s TYPE TIMESTAMPTZ', $column),
                $columns,
            ));
        }

        // Every zone-less value was written in UTC: PHP's zone is UTC, and sessions ran in the
        // server's Etc/UTC. Naming the zone keeps the session's zone out of it. A now()
        // default is dropped and set again so it stays plain now(), not now() converted to a
        // zone-less timestamp and back; a NULL default is no default.
        foreach (self::ZONE_LESS as $table => $columns) {
            $clauses = [];
            foreach ($columns as $column => $default) {
                if ($default !== null) {
                    $clauses[] = sprintf('ALTER COLUMN %s DROP DEFAULT', $column);
                }
                $clauses[] = sprintf("ALTER COLUMN %1\$s TYPE TIMESTAMPTZ USING %1\$s AT TIME ZONE 'UTC'", $column);
                if ($default === 'now()') {
                    $clauses[] = sprintf('ALTER COLUMN %s SET DEFAULT now()', $column);
                }
            }
            $this->alter($table, $clauses);
        }
    }

    public function down(Schema $schema): void
    {
        // Rounding to whole seconds loses the fractions for good, and can carry a value into the
        // next second, minute or day. The zone-less columns get the UTC wall-clock time.
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        foreach (self::ZONE_LESS as $table => $columns) {
            $clauses = [];
            foreach ($columns as $column => $default) {
                if ($default !== null) {
                    $clauses[] = sprintf('ALTER COLUMN %s DROP DEFAULT', $column);
                }
                $clauses[] = sprintf("ALTER COLUMN %1\$s TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING %1\$s AT TIME ZONE 'UTC'", $column);
                if ($default !== null) {
                    $clauses[] = sprintf('ALTER COLUMN %s SET DEFAULT %s', $column, $default);
                }
            }
            $this->alter($table, $clauses);
        }

        foreach (self::WHOLE_SECOND as $table => $columns) {
            $this->alter($table, array_map(
                static fn (string $column): string => sprintf('ALTER COLUMN %s TYPE TIMESTAMP(0) WITH TIME ZONE', $column),
                $columns,
            ));
        }
    }

    /** @param list<string> $clauses */
    private function alter(string $table, array $clauses): void
    {
        $this->addSql(sprintf('ALTER TABLE %s %s', $table, implode(', ', $clauses)));
    }
}
