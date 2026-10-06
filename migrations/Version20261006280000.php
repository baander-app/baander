<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006280000 extends AbstractMigration
{
    /**
     * References that had no foreign key.
     *
     * @var array<string, array{string, string, string, string}> name => [table, column, referenced table, ON DELETE]
     */
    private const NEW_FOREIGN_KEYS = [
        'fk_oauth_clients_user_id' => ['oauth_clients', 'user_id', 'users', 'CASCADE'],
        'fk_pairing_sessions_server_id' => ['pairing_sessions', 'server_id', 'server_instances', 'CASCADE'],
        'fk_party_events_session_id' => ['party_events', 'session_id', 'party_sessions', 'CASCADE'],
        'fk_party_events_user_id' => ['party_events', 'user_id', 'users', 'CASCADE'],
        'fk_recommendation_jobs_original_job_id' => ['recommendation_jobs', 'original_job_id', 'recommendation_jobs', 'SET NULL'],
        'fk_recommendation_jobs_user_id' => ['recommendation_jobs', 'user_id', 'users', 'SET NULL'],
        'fk_transcode_jobs_video_id' => ['transcode_jobs', 'video_id', 'videos', 'CASCADE'],
        'fk_transcode_sessions_video_id' => ['transcode_sessions', 'video_id', 'videos', 'CASCADE'],
        'fk_user_favorites_user_id' => ['user_favorites', 'user_id', 'users', 'CASCADE'],
        'fk_user_theme_moods_user_id' => ['user_theme_moods', 'user_id', 'users', 'CASCADE'],
    ];

    /**
     * Station references that blocked deleting a station.
     *
     * @var array<string, array{string, string, string, string}> name => [table, column, referenced table, ON DELETE]
     */
    private const CHANGED_FOREIGN_KEYS = [
        'fk_radio_sessions_active_station_id' => ['radio_sessions', 'active_station_id', 'radio_stations', 'SET NULL'],
        'fk_starred_stations_station_id' => ['starred_stations', 'station_id', 'radio_stations', 'CASCADE'],
    ];

    /**
     * Foreign keys with no index leading with their column. Deleting or updating a referenced row scanned the table.
     *
     * @var array<string, array{string, string}> index => [table, column]
     */
    private const FOREIGN_KEY_INDEXES = [
        'idx_albums_cover_image_id' => ['albums', 'cover_image_id'],
        'idx_artists_cover_image_id' => ['artists', 'cover_image_id'],
        'idx_country_subscriptions_source_id' => ['country_subscriptions', 'source_id'],
        'idx_genres_parent_id' => ['genres', 'parent_id'],
        'idx_movie_video_movie_id' => ['movie_video', 'movie_id'],
        'idx_movie_video_video_id' => ['movie_video', 'video_id'],
        'idx_oauth_auth_codes_client_id' => ['oauth_auth_codes', 'client_id'],
        'idx_oauth_auth_codes_user_id' => ['oauth_auth_codes', 'user_id'],
        'idx_oauth_clients_user_id' => ['oauth_clients', 'user_id'],
        'idx_oauth_device_codes_client_id' => ['oauth_device_codes', 'client_id'],
        'idx_oauth_device_codes_user_id' => ['oauth_device_codes', 'user_id'],
        'idx_oauth_refresh_tokens_previous_refresh_token_id' => ['oauth_refresh_tokens', 'previous_refresh_token_id'],
        'idx_pairing_sessions_server_id' => ['pairing_sessions', 'server_id'],
        'idx_party_events_user_id' => ['party_events', 'user_id'],
        'idx_playlist_collaborators_user_id' => ['playlist_collaborators', 'user_id'],
        'idx_playlist_statistics_playlist_id' => ['playlist_statistics', 'playlist_id'],
        'idx_radio_sessions_active_station_id' => ['radio_sessions', 'active_station_id'],
        'idx_recommendation_jobs_original_job_id' => ['recommendation_jobs', 'original_job_id'],
        'idx_starred_stations_station_id' => ['starred_stations', 'station_id'],
        'idx_transcode_sessions_video_id' => ['transcode_sessions', 'video_id'],
        'idx_user_library_access_library_id' => ['user_library_access', 'library_id'],
    ];

    public function getDescription(): string
    {
        return 'Add the missing foreign keys and foreign-key indexes, and let deleting a radio station release its stars and sessions.';
    }

    public function up(Schema $schema): void
    {
        // Rows that refer to a row that no longer exists cannot be reached through their owner.
        // Owned rows are deleted; optional references are cleared.
        $this->addSql('DELETE FROM user_favorites f WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.id = f.user_id)');
        $this->addSql('DELETE FROM user_theme_moods m WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.id = m.user_id)');
        $this->addSql('DELETE FROM oauth_clients c WHERE c.user_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = c.user_id)');
        $this->addSql('DELETE FROM party_events e WHERE NOT EXISTS (SELECT 1 FROM party_sessions s WHERE s.id = e.session_id) OR NOT EXISTS (SELECT 1 FROM users u WHERE u.id = e.user_id)');
        $this->addSql('DELETE FROM pairing_sessions p WHERE NOT EXISTS (SELECT 1 FROM server_instances s WHERE s.id = p.server_id)');
        // Deleting a job also deletes its sessions through fk_transcode_sessions_job_id.
        $this->addSql('DELETE FROM transcode_jobs j WHERE NOT EXISTS (SELECT 1 FROM videos v WHERE v.id = j.video_id)');
        $this->addSql('DELETE FROM transcode_sessions s WHERE NOT EXISTS (SELECT 1 FROM videos v WHERE v.id = s.video_id)');
        $this->addSql('UPDATE recommendation_jobs j SET user_id = NULL WHERE j.user_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = j.user_id)');
        $this->addSql('UPDATE recommendation_jobs j SET original_job_id = NULL WHERE j.original_job_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM recommendation_jobs o WHERE o.id = j.original_job_id)');

        foreach (self::NEW_FOREIGN_KEYS as $name => [$table, $column, $referenced, $onDelete]) {
            $this->addSql(self::addForeignKey($name, $table, $column, $referenced, $onDelete));
        }

        foreach (self::CHANGED_FOREIGN_KEYS as $name => [$table, $column, $referenced, $onDelete]) {
            $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $table, $name));
            $this->addSql(self::addForeignKey($name, $table, $column, $referenced, $onDelete));
        }

        foreach (self::FOREIGN_KEY_INDEXES as $index => [$table, $column]) {
            $this->addSql(sprintf('CREATE INDEX %s ON %s (%s)', $index, $table, $column));
        }
    }

    public function down(Schema $schema): void
    {
        // The orphaned rows deleted or cleared by up() are not restored.
        foreach (array_keys(self::FOREIGN_KEY_INDEXES) as $index) {
            $this->addSql(sprintf('DROP INDEX %s', $index));
        }

        foreach (self::CHANGED_FOREIGN_KEYS as $name => [$table, $column, $referenced]) {
            $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $table, $name));
            $this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (id)', $table, $name, $column, $referenced));
        }

        foreach (self::NEW_FOREIGN_KEYS as $name => [$table]) {
            $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $table, $name));
        }
    }

    private static function addForeignKey(string $name, string $table, string $column, string $referenced, string $onDelete): string
    {
        return sprintf('ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (id) ON DELETE %s', $table, $name, $column, $referenced, $onDelete);
    }
}
