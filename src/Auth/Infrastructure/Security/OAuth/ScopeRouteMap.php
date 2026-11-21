<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

/**
 * Which routes a delegated access token may reach.
 *
 * A token issued to a client other than Baander's own is delegated: it reaches a public
 * route, or a route one of its scopes covers, and nothing else. Every route is named in
 * exactly one list. A route that requires an admin role is first-party only, whatever area
 * it belongs to. A test holds the lists to the compiled routes, so a new route stays out of
 * reach of delegated tokens until it is classified here.
 */
final class ScopeRouteMap
{
    public const string PROFILE = 'profile';
    public const string EMAIL = 'email';
    public const string LIBRARY = 'library';
    public const string PLAYLIST = 'playlist';

    /**
     * Routes that need no access token: sign-in, registration and recovery, the OAuth
     * endpoints clients call with their own authentication, discovery documents, probes,
     * the web app shell, and media URLs whose signature stands in for the token.
     *
     * @var list<string>
     */
    public const array PUBLIC_ROUTES = [
        'oauth_authorization_server_metadata', 'jwks', 'oauth_par', 'oauth_token', 'oauth_device_authorize',
        'passkey_authenticate_options', 'passkey_authenticate', 'auth_passkey_login_passkey', 'auth_register',
        'auth_refresh', 'auth_password_reset_request', 'auth_password_reset', 'auth_email_verify',
        'auth_login_login', 'stream_original_video', 'stream_original_track', 'health_check', 'health_ready',
        'health_live', 'prometheus_metrics', 'stream_artifact_master', 'stream_artifact_media',
        'stream_artifact_init', 'stream_artifact_segment', 'stream_master_manifest', 'stream_media_manifest',
        'stream_dash_manifest', 'stream_subtitle_manifest', 'stream_segment_init_segment',
        'stream_segment_segment', 'stream_segment_subtitle_segment', 'spa_index', 'spa_catchall',
    ];

    /**
     * Routes only Baander's own web and desktop apps reach.
     *
     * @var list<string>
     */
    public const array FIRST_PARTY_ROUTES = [
        // Administration, monitoring and diagnostics; each requires an admin role.
        'admin_activity_summary', 'admin_activity_top_tracks', 'admin_activity_top_artists',
        'admin_activity_engagement', 'admin_login_blocks_list', 'admin_login_blocks_delete',
        'admin_login_blocks_delete_all', 'admin_oauth_clients_list', 'admin_oauth_clients_create',
        'admin_oauth_clients_rotate_secret', 'admin_oauth_clients_replace_keys', 'admin_oauth_clients_revoke',
        'admin_users_list', 'admin_users_create', 'admin_users_update', 'admin_users_delete',
        'admin_users_assign_roles', 'admin_users_reset_password', 'admin_users_reset_totp', 'admin_users_verify_email', 'admin_users_disable', 'admin_users_enable',
        'admin_user_settings_index', 'admin_user_settings_set', 'admin_user_settings_reset',
        'admin_album_delete_preview', 'admin_album_delete', 'admin_song_delete_preview', 'admin_song_delete',
        'admin_albums_duplicates', 'admin_metadata_match_reviews', 'admin_metadata_match_candidates',
        'admin_metadata_match_comparison', 'admin_metadata_match_apply', 'admin_user_libraries_index',
        'admin_user_libraries_grant', 'admin_user_libraries_revoke', 'admin_lyrics_coverage',
        'admin_lyrics_bulk_fetch', 'admin_lyrics_sync_status', 'admin_media_storage_stats',
        'admin_media_prune_missing', 'admin_media_missing_check', 'admin_metadata_sync_status',
        'admin_metadata_trigger_sync', 'admin_metadata_providers', 'admin_qol_status', 'admin_qol_streams',
        'admin_qol_profile', 'admin_qol_reset', 'admin_recommendations_coverage',
        'admin_recommendations_source_quality', 'admin_recommendations_freshness',
        'admin_recommendations_generate', 'admin_recommendations_job_status',
        'admin_recommendations_job_requeue', 'admin_recommendations_job_cancel',
        'admin_recommendations_jobs_list', 'admin_scheduler_list', 'admin_scheduler_show',
        'admin_scheduler_create', 'admin_scheduler_update', 'admin_scheduler_delete', 'admin_scheduler_pause',
        'admin_scheduler_resume', 'admin_scheduler_trigger', 'admin_scheduler_enable',
        'admin_scheduler_disable', 'admin_scheduler_commands', 'debug_config_check', 'debug_coroutines',
        'monitor_analytics_summary', 'monitor_analytics_timing', 'monitor_analytics_failures',
        'monitor_status', 'monitor_jobs', 'monitor_jobs_detail', 'monitor_prune', 'monitor_jobs_retry',
        'monitor_jobs_cancel', 'monitor_ratelimiters_list', 'monitor_ratelimiters_clear_all',
        'monitor_ratelimiters_clear', 'debug_stats', 'debug_spans', 'debug_spans_clear',
        'admin_settings_index', 'admin_settings_definitions', 'admin_settings_update', 'admin_settings_reset',
        'monitor_transport_status', 'monitor_transport_failed_list', 'monitor_transport_failed_show',
        'monitor_transport_failed_flush', 'monitor_transport_failed_retry', 'monitor_transport_failed_remove',
        'debug_workers',
        // Catalog, library, lyrics, metadata, radio source, transcode job, webhook and server
        // registration administration; each requires an admin role.
        'album_update', 'album_merge', 'album_destroy', 'album_cover_upload', 'album_cover_delete',
        'artist_store', 'artist_update', 'artist_destroy', 'artist_add_song', 'artist_remove_song',
        'artist_update_song_role', 'artist_add_album', 'artist_remove_album', 'artist_update_album_role',
        'artist_cover_upload', 'artist_cover_delete',
        'extract_covers_app_catalog_interface_extractcovers__invoke', 'genre_store', 'genre_update',
        'genre_destroy', 'genre_add_song', 'genre_remove_song', 'genre_add_album', 'genre_remove_album',
        'movie_update', 'movie_destroy', 'song_update', 'song_destroy', 'discovery_register', 'library_store',
        'library_update', 'library_delete', 'library_scan', 'library_validate_path', 'library_scan_all',
        'lyrics_song_lyrics_fetch', 'lyrics_search', 'lyrics_apply', 'metadata_extract', 'metadata_match',
        'webhook_index', 'webhook_create', 'webhook_update', 'webhook_delete', 'webhook_rotate_secret',
        'radio_source_create', 'transcode_job_cleanup',
        // Account security, sign-in sessions and live connections.
        'live_recovery', 'passkey_list', 'passkey_options', 'passkey_register', 'passkey_delete',
        'totp_setup', 'totp_enable', 'totp_disable', 'auth_logout', 'auth_me_update',
        'auth_me_email_verification', 'auth_me_email', 'auth_me_password', 'auth_websocket_ticket',
        // Consent, device approval and client management.
        'oauth_clients_index', 'oauth_clients_create', 'oauth_clients_revoke', 'oauth_authorize',
        'oauth_authorize_decision', 'oauth_revoke', 'oauth_device_verify', 'oauth_device_approve',
        // MusicBrainz lookups for metadata matching, and server pairing.
        'discovery_pairing_code', 'discovery_qr_payload', 'discovery_complete_pairing',
        'metadata_browse_artist', 'metadata_browse_release_group', 'metadata_search_artist',
        'metadata_search_album', 'metadata_search_song',
        // Notifications, push subscriptions and listening parties.
        'notification_index', 'notification_unread_count', 'notification_mark_read',
        'notification_mark_all_read', 'notification_delete', 'notification_preference_index',
        'notification_preference_update', 'push_subscribe', 'push_unsubscribe', 'push_remove_all',
        'party_invitation_index', 'party_invitation_invite', 'party_invitation_revoke', 'party_member_index',
        'party_member_update_me', 'party_member_transfer_host', 'party_session_create', 'party_session_index',
        'party_session_show', 'party_session_join', 'party_session_leave', 'party_session_sync',
        'party_session_end',
        // Preferences and settings.
        'accent_color_index', 'accent_color_update', 'audio_preferences_index', 'audio_preferences_update',
        'audio_preferences_history', 'audio_preferences_rollback', 'eq_profiles_index', 'eq_profiles_create',
        'eq_profiles_show', 'eq_profiles_update', 'eq_profiles_delete', 'eq_profiles_activate',
        'layout_preferences_index', 'layout_preferences_update', 'layout_preferences_history',
        'layout_preferences_rollback', 'player_preferences_index', 'player_preferences_update',
        'player_preferences_history', 'player_preferences_rollback', 'sidebar_config_get',
        'sidebar_config_update', 'sidebar_config_delete', 'theme_mood_index', 'theme_mood_update',
        'user_settings_index', 'user_settings_set', 'user_settings_reset',
    ];

    /**
     * Routes a delegated token reaches when it holds any of the listed scopes.
     *
     * @var array<string, list<string>>
     */
    public const array SCOPED_ROUTES = [
        // Reading the user's own profile.
        'auth_me' => [self::PROFILE, self::EMAIL],
        // Browsing, streaming, playback and its coordination, transcoding, devices, favorites,
        // listening history, recommendations and radio.
        'transcode_job_list' => [self::LIBRARY],
        'transcode_session_list' => [self::LIBRARY],
        'activity_history' => [self::LIBRARY],
        'activity_play' => [self::LIBRARY],
        'activity_love' => [self::LIBRARY],
        'activity_loved' => [self::LIBRARY],
        'user_recent' => [self::LIBRARY],
        'album_index' => [self::LIBRARY],
        'album_show' => [self::LIBRARY],
        'album_cover' => [self::LIBRARY],
        'album_duplicates' => [self::LIBRARY],
        'artist_index' => [self::LIBRARY],
        'artist_show' => [self::LIBRARY],
        'genre_index' => [self::LIBRARY],
        'genre_show' => [self::LIBRARY],
        'movie_index' => [self::LIBRARY],
        'movie_show' => [self::LIBRARY],
        'movie_director_index' => [self::LIBRARY],
        'movie_director_show' => [self::LIBRARY],
        'movie_director_movies' => [self::LIBRARY],
        'person_index' => [self::LIBRARY],
        'person_show' => [self::LIBRARY],
        'person_credits' => [self::LIBRARY],
        'person_movie_credits' => [self::LIBRARY],
        'person_artist' => [self::LIBRARY],
        'tv_series_index' => [self::LIBRARY],
        'tv_series_show' => [self::LIBRARY],
        'tv_series_episodes' => [self::LIBRARY],
        'tv_episode_show' => [self::LIBRARY],
        'tv_series_credits' => [self::LIBRARY],
        'tv_episode_credits' => [self::LIBRARY],
        'song_index' => [self::LIBRARY],
        'song_show' => [self::LIBRARY],
        'favorites_index' => [self::LIBRARY],
        'favorites_add' => [self::LIBRARY],
        'favorites_remove' => [self::LIBRARY],
        'library_index' => [self::LIBRARY],
        'library_show' => [self::LIBRARY],
        'library_stats' => [self::LIBRARY],
        'lyrics_song_lyrics' => [self::LIBRARY],
        'stream_delivery' => [self::LIBRARY],
        'image_show' => [self::LIBRARY],
        'image_file' => [self::LIBRARY],
        'image_blurhash' => [self::LIBRARY],
        'stream_track' => [self::LIBRARY],
        'radio_subscription_list' => [self::LIBRARY],
        'radio_subscription_subscribe' => [self::LIBRARY],
        'radio_subscription_unsubscribe' => [self::LIBRARY],
        'radio_subscription_refresh' => [self::LIBRARY],
        'radio_session_get' => [self::LIBRARY],
        'radio_session_start' => [self::LIBRARY],
        'radio_session_stop' => [self::LIBRARY],
        'radio_source_list' => [self::LIBRARY],
        'radio_countries' => [self::LIBRARY],
        'radio_stations' => [self::LIBRARY],
        'radio_starred_list' => [self::LIBRARY],
        'radio_starred_star' => [self::LIBRARY],
        'radio_starred_unstar' => [self::LIBRARY],
        'recommendation_index' => [self::LIBRARY],
        'recommendation_for_you' => [self::LIBRARY],
        'recommendation_by_source' => [self::LIBRARY],
        'recommendation_targeting' => [self::LIBRARY],
        'recommendation_store' => [self::LIBRARY],
        'recommendation_destroy' => [self::LIBRARY],
        'recommendation_destroy_by_source' => [self::LIBRARY],
        'device_register' => [self::LIBRARY],
        'device_list' => [self::LIBRARY],
        'device_rename' => [self::LIBRARY],
        'device_forget' => [self::LIBRARY],
        'playback_endpoint' => [self::LIBRARY],
        'playback_transfer' => [self::LIBRARY],
        'playback_ack' => [self::LIBRARY],
        'playback_observe' => [self::LIBRARY],
        'playback_control' => [self::LIBRARY],
        'playback_reconcile' => [self::LIBRARY],
        'playback_recover_local' => [self::LIBRARY],
        'playback_close' => [self::LIBRARY],
        'playback_override' => [self::LIBRARY],
        'playback_snapshot' => [self::LIBRARY],
        'stream_quality_ladder' => [self::LIBRARY],
        'stream_signing_sign' => [self::LIBRARY],
        'transcode_job_index' => [self::LIBRARY],
        'transcode_job_show' => [self::LIBRARY],
        'transcode_job_metrics' => [self::LIBRARY],
        'transcode_session_create' => [self::LIBRARY],
        'transcode_session_index' => [self::LIBRARY],
        'transcode_session_show' => [self::LIBRARY],
        'transcode_session_pause' => [self::LIBRARY],
        'transcode_session_resume' => [self::LIBRARY],
        'transcode_session_cancel' => [self::LIBRARY],
        'transcode_session_update' => [self::LIBRARY],
        'transcode_session_position' => [self::LIBRARY],
        // Playlists.
        'playlist_index' => [self::PLAYLIST],
        'playlist_store' => [self::PLAYLIST],
        'playlist_show' => [self::PLAYLIST],
        'playlist_update' => [self::PLAYLIST],
        'playlist_destroy' => [self::PLAYLIST],
        'playlist_add_song' => [self::PLAYLIST],
        'playlist_remove_song' => [self::PLAYLIST],
        'playlist_add_collaborator' => [self::PLAYLIST],
        'playlist_remove_collaborator' => [self::PLAYLIST],
        'playlist_reorder' => [self::PLAYLIST],
    ];

    /**
     * Whether a delegated token holding these scopes may reach the route.
     *
     * @param list<string> $scopes
     */
    public static function allowsDelegated(mixed $route, array $scopes): bool
    {
        if (!is_string($route)) {
            return false;
        }

        return in_array($route, self::PUBLIC_ROUTES, true)
            || array_intersect(self::SCOPED_ROUTES[$route] ?? [], $scopes) !== [];
    }
}
