<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006230000 extends AbstractMigration
{
    /**
     * Foreign keys and unique constraints: table => [current name => conventional name].
     * Renaming a unique constraint also renames the index that backs it.
     */
    private const CONSTRAINTS = [
        'albums' => [
            'albums_library_id_fkey' => 'fk_albums_library_id',
            'fk_albums_cover_image' => 'fk_albums_cover_image_id',
        ],
        'artist_album' => [
            'artist_album_album_id_fkey' => 'fk_artist_album_album_id',
            'artist_album_artist_id_fkey' => 'fk_artist_album_artist_id',
        ],
        'artist_song' => [
            'artist_song_artist_id_fkey' => 'fk_artist_song_artist_id',
            'artist_song_song_id_fkey' => 'fk_artist_song_song_id',
        ],
        'country_subscriptions' => [
            'country_subscriptions_source_id_fkey' => 'fk_country_subscriptions_source_id',
        ],
        'domain_event_outbox_delivery' => [
            'domain_event_outbox_delivery_channel_notification_id_key' => 'uniq_domain_event_outbox_delivery_channel_notification_id',
        ],
        'domain_event_outbox_receipt' => [
            'domain_event_outbox_receipt_outbox_id_fkey' => 'fk_domain_event_outbox_receipt_outbox_id',
        ],
        'genre_album' => [
            'genre_album_album_id_fkey' => 'fk_genre_album_album_id',
            'genre_album_genre_id_fkey' => 'fk_genre_album_genre_id',
        ],
        'genre_movie' => [
            'genre_movie_genre_id_fkey' => 'fk_genre_movie_genre_id',
            'genre_movie_movie_id_fkey' => 'fk_genre_movie_movie_id',
        ],
        'genre_song' => [
            'genre_song_genre_id_fkey' => 'fk_genre_song_genre_id',
            'genre_song_song_id_fkey' => 'fk_genre_song_song_id',
        ],
        'genres' => [
            'genres_parent_id_fkey' => 'fk_genres_parent_id',
        ],
        'images' => [
            'images_album_id_fkey' => 'fk_images_album_id',
            'images_artist_id_fkey' => 'fk_images_artist_id',
            'images_playlist_id_fkey' => 'fk_images_playlist_id',
        ],
        'lyrics' => [
            'lyrics_song_id_fkey' => 'fk_lyrics_song_id',
        ],
        'media_activities' => [
            'media_activities_album_id_fkey' => 'fk_media_activities_album_id',
            'media_activities_artist_id_fkey' => 'fk_media_activities_artist_id',
            'media_activities_movie_id_fkey' => 'fk_media_activities_movie_id',
            'media_activities_song_id_fkey' => 'fk_media_activities_song_id',
            'media_activities_user_id_fkey' => 'fk_media_activities_user_id',
        ],
        'movie_video' => [
            'movie_video_movie_id_fkey' => 'fk_movie_video_movie_id',
            'movie_video_video_id_fkey' => 'fk_movie_video_video_id',
        ],
        'movies' => [
            'movies_library_id_fkey' => 'fk_movies_library_id',
        ],
        'oauth_access_tokens' => [
            'oauth_access_tokens_client_id_fkey' => 'fk_oauth_access_tokens_client_id',
            'oauth_access_tokens_user_id_fkey' => 'fk_oauth_access_tokens_user_id',
        ],
        'oauth_auth_codes' => [
            'oauth_auth_codes_client_id_fkey' => 'fk_oauth_auth_codes_client_id',
            'oauth_auth_codes_user_id_fkey' => 'fk_oauth_auth_codes_user_id',
        ],
        'oauth_device_codes' => [
            'oauth_device_codes_client_id_fkey' => 'fk_oauth_device_codes_client_id',
            'oauth_device_codes_user_id_fkey' => 'fk_oauth_device_codes_user_id',
        ],
        'oauth_refresh_tokens' => [
            'oauth_refresh_tokens_access_token_id_fkey' => 'fk_oauth_refresh_tokens_access_token_id',
            'oauth_refresh_tokens_previous_refresh_token_id_fkey' => 'fk_oauth_refresh_tokens_previous_refresh_token_id',
        ],
        'oauth_token_metadata' => [
            'oauth_token_metadata_token_id_fkey' => 'fk_oauth_token_metadata_token_id',
        ],
        'party_members' => [
            'fk_57514dd4613fecdf' => 'fk_party_members_session_id',
        ],
        'passkeys' => [
            'passkeys_user_id_fkey' => 'fk_passkeys_user_id',
        ],
        'playlist_collaborators' => [
            'playlist_collaborators_playlist_id_fkey' => 'fk_playlist_collaborators_playlist_id',
            'playlist_collaborators_user_id_fkey' => 'fk_playlist_collaborators_user_id',
        ],
        'playlist_song' => [
            'playlist_song_playlist_id_fkey' => 'fk_playlist_song_playlist_id',
            'playlist_song_song_id_fkey' => 'fk_playlist_song_song_id',
        ],
        'playlist_statistics' => [
            'playlist_statistics_playlist_id_fkey' => 'fk_playlist_statistics_playlist_id',
        ],
        'playlists' => [
            'playlists_user_id_fkey' => 'fk_playlists_user_id',
        ],
        'push_subscriptions' => [
            '_fkpush_subscriptions_user_id' => 'fk_push_subscriptions_user_id',
        ],
        'radio_sessions' => [
            'radio_sessions_active_station_id_fkey' => 'fk_radio_sessions_active_station_id',
        ],
        'radio_stations' => [
            'radio_stations_source_id_fkey' => 'fk_radio_stations_source_id',
        ],
        'recommendations' => [
            'recommendations_user_id_fkey' => 'fk_recommendations_user_id',
        ],
        'scheduler_occurrence_executions' => [
            'scheduler_occurrence_executions_attempt_id_key' => 'uniq_scheduler_occurrence_executions_attempt_id',
            'scheduler_occurrence_executions_occurrence_job_fk' => 'fk_scheduler_occurrence_executions_occurrence_id_job_id',
        ],
        'scheduler_occurrences' => [
            'scheduler_occurrences_id_job_uidx' => 'uniq_scheduler_occurrences_id_job_id',
        ],
        'songs' => [
            'songs_album_id_fkey' => 'fk_songs_album_id',
        ],
        'starred_stations' => [
            'starred_stations_station_id_fkey' => 'fk_starred_stations_station_id',
        ],
        'third_party_credentials' => [
            'third_party_credentials_user_id_fkey' => 'fk_third_party_credentials_user_id',
        ],
        'transcode_sessions' => [
            'fk_transcode_session_job' => 'fk_transcode_sessions_job_id',
        ],
        'user_library_access' => [
            'fk_user_library_access_library' => 'fk_user_library_access_library_id',
            'fk_user_library_access_user' => 'fk_user_library_access_user_id',
        ],
        'webhook_delivery_logs' => [
            'fk_2afcb9d15c9ba60b' => 'fk_webhook_delivery_logs_webhook_id',
        ],
        'worker_deployment_containers' => [
            'worker_deployment_containers_daemon_id_container_id_key' => 'uniq_worker_deployment_containers_daemon_id_container_id',
        ],
        'worker_deployment_retirements' => [
            'worker_deployment_retirements_daemon_id_container_id_key' => 'uniq_worker_deployment_retirements_daemon_id_container_id',
        ],
    ];

    /** Indexes that back no constraint: current name => conventional name. */
    private const INDEXES = [
        'albums_public_id_unique' => 'uniq_albums_public_id',
        'artist_album_role_unique' => 'uniq_artist_album_artist_id_album_id_role',
        'artist_song_role_unique' => 'uniq_artist_song_artist_id_song_id_role',
        'artists_public_id_unique' => 'uniq_artists_public_id',
        'audio_preferences_user_id_key' => 'uniq_audio_preferences_user_id',
        'country_subscriptions_user_id_source_id_country_code_key' => 'uniq_country_subscriptions_user_id_source_id_country_code',
        'devices_user_id_device_id_key' => 'uniq_devices_user_id_device_id',
        'idx_outbox_claim_expiry' => 'idx_domain_event_outbox_claim_expiry',
        'idx_outbox_delivery_pending' => 'idx_domain_event_outbox_delivery_pending',
        'email_verification_tokens_token_unique' => 'uniq_email_verification_tokens_token',
        'eq_device_profiles_user_id_name_key' => 'uniq_eq_device_profiles_user_id_name',
        'genre_album_unique' => 'uniq_genre_album_genre_id_album_id',
        'genre_movie_unique' => 'uniq_genre_movie_genre_id_movie_id',
        'genre_song_unique' => 'uniq_genre_song_genre_id_song_id',
        'genres_name_lower_unique' => 'uniq_genres_name_lower',
        'genres_slug_unique' => 'uniq_genres_slug',
        'images_public_id_unique' => 'uniq_images_public_id',
        'layout_preferences_user_id_key' => 'uniq_layout_preferences_user_id',
        'libraries_slug_unique' => 'uniq_libraries_slug',
        'library_file_path_unique' => 'uniq_library_file_index_library_id_path',
        'listening_sessions_user_id_key' => 'uniq_listening_sessions_user_id',
        'idx_login_blocks_ip_created' => 'idx_login_blocks_ip_address_created_at',
        'lyrics_lrclib_id_unique' => 'uniq_lyrics_lrclib_id',
        'lyrics_song_id_unique' => 'uniq_lyrics_song_id',
        'idx_media_activities_type_user' => 'idx_media_activities_activity_type_user_id',
        'media_activities_public_id_unique' => 'uniq_media_activities_public_id',
        'tmdb_collection_id_unique' => 'uniq_movie_collections_tmdb_collection_id',
        'movies_public_id_unique' => 'uniq_movies_public_id',
        'notification_preferences_user_id_category_channel_key' => 'uniq_notification_preferences_user_id_category_channel',
        'idx_notifications_user_created' => 'idx_notifications_user_id_created_at',
        'idx_notifications_user_read' => 'idx_notifications_user_id_is_read',
        'notifications_public_id_key' => 'uniq_notifications_public_id',
        'oauth_access_tokens_token_id_unique' => 'uniq_oauth_access_tokens_token_id',
        'oauth_auth_codes_code_id_unique' => 'uniq_oauth_auth_codes_code_id',
        'oauth_clients_public_id_unique' => 'uniq_oauth_clients_public_id',
        'oauth_device_codes_device_code_unique' => 'uniq_oauth_device_codes_device_code',
        'oauth_device_codes_user_code_unique' => 'uniq_oauth_device_codes_user_code',
        'oauth_refresh_tokens_token_id_unique' => 'uniq_oauth_refresh_tokens_token_id',
        'oauth_token_metadata_token_id_unique' => 'uniq_oauth_token_metadata_token_id',
        'pairing_sessions_pairing_code_key' => 'uniq_pairing_sessions_pairing_code',
        'pairing_sessions_public_id_key' => 'uniq_pairing_sessions_public_id',
        'party_members_public_id_key' => 'uniq_party_members_public_id',
        'party_members_user_id_session_id_key' => 'uniq_party_members_user_id_session_id',
        'party_sessions_public_id_key' => 'uniq_party_sessions_public_id',
        'passkeys_credential_id_unique' => 'uniq_passkeys_credential_id',
        'player_preferences_user_id_key' => 'uniq_player_preferences_user_id',
        'playlist_user_unique' => 'uniq_playlist_collaborators_playlist_id_user_id',
        'playlist_song_unique' => 'uniq_playlist_song_playlist_id_song_id',
        'playlists_public_id_unique' => 'uniq_playlists_public_id',
        'idx_pref_history_user_type_version' => 'idx_preference_history_user_id_preference_type_version',
        'idx_push_subscriptions_endpoint' => 'uniq_push_subscriptions_endpoint',
        'radio_sessions_user_id_key' => 'uniq_radio_sessions_user_id',
        'idx_radio_stations_source_country' => 'idx_radio_stations_source_id_country',
        'radio_stations_source_id_external_id_key' => 'uniq_radio_stations_source_id_external_id',
        'recommendation_jobs_public_id_idx' => 'uniq_recommendation_jobs_public_id',
        'recommendation_jobs_status_idx' => 'idx_recommendation_jobs_status',
        'recommendation_jobs_user_id_idx' => 'idx_recommendation_jobs_user_id',
        'idx_recommendations_source' => 'idx_recommendations_source_type_source_id',
        'idx_recommendations_target' => 'idx_recommendations_target_type_target_id',
        'recommendations_source_target_name_user_uniq' => 'uniq_recommendations_source_target_name_user_id',
        'idx_scheduled_jobs_recovery_after' => 'idx_scheduled_jobs_recovery_after_id',
        'scheduler_occurrence_executions_unresolved_job_uidx' => 'uniq_scheduler_occurrence_executions_unresolved_job',
        'scheduler_occurrences_pending_dispatch_idx' => 'idx_scheduler_occurrences_pending_dispatch',
        'scheduler_occurrences_scheduled_job_minute_uidx' => 'uniq_scheduler_occurrences_scheduled_job_minute',
        'server_instances_public_id_key' => 'uniq_server_instances_public_id',
        'server_instances_server_url_key' => 'uniq_server_instances_server_url',
        'songs_public_id_unique' => 'uniq_songs_public_id',
        'idx_starred_stations_user' => 'idx_starred_stations_user_id',
        'starred_stations_user_id_station_id_key' => 'uniq_starred_stations_user_id_station_id',
        'third_party_credentials_public_id_unique' => 'uniq_third_party_credentials_public_id',
        'transcode_jobs_public_id_key' => 'uniq_transcode_jobs_public_id',
        'transcode_jobs_video_id_quality_tier_name_key' => 'uniq_transcode_jobs_video_id_quality_tier_name',
        'transcode_sessions_public_id_key' => 'uniq_transcode_sessions_public_id',
        'user_accent_colors_user_id_key' => 'uniq_user_accent_colors_user_id',
        'user_favorites_public_id_key' => 'uniq_user_favorites_public_id',
        'user_favorites_user_entity_key' => 'uniq_user_favorites_user_id_entity_type_entity_public_id',
        'user_favorites_user_id_idx' => 'idx_user_favorites_user_id',
        'idx_35d5e9c7a76ed395' => 'idx_user_sidebar_configs_user_id',
        'uniq_user_media' => 'uniq_user_sidebar_configs_user_id_media_type',
        'user_theme_moods_user_id_key' => 'uniq_user_theme_moods_user_id',
        'users_email_unique' => 'uniq_users_email',
        'users_public_id_unique' => 'uniq_users_public_id',
        'videos_hash_unique' => 'uniq_videos_hash',
        'videos_public_id_unique' => 'uniq_videos_public_id',
    ];

    public function getDescription(): string
    {
        return 'Rename foreign keys, unique constraints and indexes to the documented fk_/uniq_/idx_<table>_<columns> convention.';
    }

    public function up(Schema $schema): void
    {
        // Renames only: no constraint or index is dropped, rebuilt or revalidated.
        foreach (self::CONSTRAINTS as $table => $names) {
            foreach ($names as $from => $to) {
                $this->addSql(sprintf('ALTER TABLE %s RENAME CONSTRAINT %s TO %s', $table, $from, $to));
            }
        }
        foreach (self::INDEXES as $from => $to) {
            $this->addSql(sprintf('ALTER INDEX %s RENAME TO %s', $from, $to));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::CONSTRAINTS as $table => $names) {
            foreach ($names as $from => $to) {
                $this->addSql(sprintf('ALTER TABLE %s RENAME CONSTRAINT %s TO %s', $table, $to, $from));
            }
        }
        foreach (self::INDEXES as $from => $to) {
            $this->addSql(sprintf('ALTER INDEX %s RENAME TO %s', $to, $from));
        }
    }
}
