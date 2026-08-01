<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema - compressed from all previous migrations.
 *
 * This migration represents the complete database schema as of 2026-05-23.
 * All previous migrations have been compressed into this single migration.
 */
final class Version001_InitialSchema extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial database schema with all tables, indexes, and constraints';
    }

    public function up(Schema $schema): void
    {
        // Extensions
        $this->addSql('CREATE EXTENSION IF NOT EXISTS citext');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS ltree');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_stat_statements');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pgcrypto');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pgroonga');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');

        // Tables
        $this->addSql('CREATE TABLE oauth_access_tokens (
            id UUID NOT NULL,
            chain_id UUID,
            token_id TEXT NOT NULL,
            user_id UUID,
            client_id UUID NOT NULL,
            name TEXT,
            scopes JSONB,
            revoked BOOLEAN DEFAULT FALSE NOT NULL,
            expires_at TIMESTAMP(0) WITH TIME ZONE,
            last_refreshed_at TIMESTAMP(0) WITH TIME ZONE,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            dpop_jkt TEXT,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE oauth_auth_codes (
            id UUID NOT NULL,
            code_id TEXT NOT NULL,
            user_id UUID NOT NULL,
            client_id UUID NOT NULL,
            scopes JSONB,
            revoked BOOLEAN DEFAULT FALSE NOT NULL,
            expires_at TIMESTAMP(0) WITH TIME ZONE,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE oauth_clients (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            name TEXT NOT NULL,
            secret TEXT,
            provider TEXT,
            redirect TEXT NOT NULL,
            personal_access_client BOOLEAN DEFAULT FALSE NOT NULL,
            password_client BOOLEAN DEFAULT FALSE NOT NULL,
            device_client BOOLEAN DEFAULT FALSE NOT NULL,
            confidential BOOLEAN DEFAULT FALSE NOT NULL,
            first_party BOOLEAN DEFAULT FALSE NOT NULL,
            revoked BOOLEAN DEFAULT FALSE NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            user_id UUID,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE oauth_device_codes (
            id UUID NOT NULL,
            device_code TEXT NOT NULL,
            user_code TEXT NOT NULL,
            user_id UUID,
            client_id UUID NOT NULL,
            scopes JSONB,
            verification_uri TEXT NOT NULL,
            verification_uri_complete TEXT,
            expires_at TIMESTAMP(0) WITH TIME ZONE,
            "interval" INT DEFAULT 5 NOT NULL,
            last_polled_at TIMESTAMP(0) WITH TIME ZONE,
            approved BOOLEAN DEFAULT FALSE NOT NULL,
            denied BOOLEAN DEFAULT FALSE NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            consumed_at TIMESTAMP(0) WITH TIME ZONE,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE oauth_refresh_tokens (
            id UUID NOT NULL,
            chain_id UUID,
            previous_refresh_token_id UUID,
            token_id TEXT NOT NULL,
            access_token_id UUID NOT NULL,
            revoked BOOLEAN DEFAULT FALSE NOT NULL,
            expires_at TIMESTAMP(0) WITH TIME ZONE,
            used_at TIMESTAMP(0) WITH TIME ZONE,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE oauth_scopes (
            id TEXT NOT NULL,
            description TEXT NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE oauth_token_metadata (
            id UUID NOT NULL,
            token_id UUID NOT NULL,
            user_agent TEXT,
            device_operating_system TEXT,
            device_name TEXT,
            client_fingerprint TEXT,
            session_id TEXT,
            ip_address TEXT,
            ip_history JSONB DEFAULT \'[]\'::jsonb NOT NULL,
            ip_change_count INT DEFAULT 0 NOT NULL,
            country_code TEXT,
            city TEXT,
            last_geo_notification_at TIMESTAMP(0) WITH TIME ZONE,
            broadcast_token TEXT,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE users (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            name TEXT NOT NULL,
            email CITEXT NOT NULL,
            email_verified_at TIMESTAMP(0) WITH TIME ZONE,
            password TEXT NOT NULL,
            totp_secret TEXT,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            roles JSONB DEFAULT \'["ROLE_USER"]\'::jsonb NOT NULL,
            disabled BOOLEAN DEFAULT FALSE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE password_reset_tokens (
            email CITEXT NOT NULL,
            token TEXT NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            expires_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            PRIMARY KEY (email)
        )');

        $this->addSql('CREATE TABLE passkeys (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            name TEXT NOT NULL,
            credential_id TEXT NOT NULL,
            data JSONB DEFAULT \'{}\'::jsonb NOT NULL,
            counter INT DEFAULT 0 NOT NULL,
            last_used_at TIMESTAMP(0) WITH TIME ZONE,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE libraries (
            id UUID NOT NULL,
            name TEXT NOT NULL,
            slug TEXT NOT NULL,
            path TEXT NOT NULL,
            type TEXT NOT NULL,
            sort_order INT DEFAULT 0 NOT NULL,
            last_scan TIMESTAMP(0) WITH TIME ZONE,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            scan_status TEXT,
            filesystem_type TEXT DEFAULT \'local\'::text NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE artists (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            name TEXT NOT NULL,
            country TEXT,
            gender TEXT,
            type TEXT,
            life_span_begin DATE,
            life_span_end DATE,
            disambiguation TEXT,
            sort_name TEXT,
            biography TEXT,
            mbid TEXT,
            discogs_id TEXT,
            spotify_id TEXT,
            locked_fields JSONB DEFAULT \'{}\'::jsonb NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            cover_image_id UUID,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE albums (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            library_id UUID NOT NULL,
            title TEXT NOT NULL,
            type TEXT NOT NULL,
            mbid TEXT,
            discogs_id TEXT,
            spotify_id TEXT,
            year INT,
            label TEXT,
            catalog_number TEXT,
            barcode TEXT,
            country TEXT,
            language TEXT,
            disambiguation TEXT,
            annotation TEXT,
            locked_fields JSONB DEFAULT \'{}\'::jsonb NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            cover_image_id UUID,
            merged_from JSONB DEFAULT \'[]\'::jsonb NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE songs (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            album_id UUID NOT NULL,
            title TEXT NOT NULL,
            path TEXT NOT NULL,
            size INT NOT NULL,
            mime_type TEXT NOT NULL,
            length DOUBLE PRECISION,
            lyrics TEXT,
            track INT,
            disc INT,
            year INT,
            comment TEXT,
            hash TEXT,
            bitrate INT,
            sample_rate INT,
            channels INT,
            codec TEXT,
            explicit BOOLEAN DEFAULT FALSE NOT NULL,
            energy DOUBLE PRECISION,
            danceability DOUBLE PRECISION,
            valence DOUBLE PRECISION,
            acousticness DOUBLE PRECISION,
            instrumentalness DOUBLE PRECISION,
            liveness DOUBLE PRECISION,
            spechiness DOUBLE PRECISION,
            loudness DOUBLE PRECISION,
            mbid TEXT,
            discogs_id TEXT,
            spotify_id TEXT,
            locked_fields JSONB DEFAULT \'{}\'::jsonb NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE genres (
            id UUID NOT NULL,
            parent_id UUID,
            name TEXT NOT NULL,
            slug TEXT NOT NULL,
            mbid TEXT,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE artist_album (
            id UUID NOT NULL,
            artist_id UUID NOT NULL,
            album_id UUID NOT NULL,
            role TEXT,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE artist_song (
            id UUID NOT NULL,
            artist_id UUID NOT NULL,
            song_id UUID NOT NULL,
            role TEXT,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE genre_album (
            id UUID NOT NULL,
            genre_id UUID NOT NULL,
            album_id UUID NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE genre_song (
            id UUID NOT NULL,
            genre_id UUID NOT NULL,
            song_id UUID NOT NULL,
            "position" INT,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE lyrics (
            id UUID NOT NULL,
            song_id UUID NOT NULL,
            plain_lyrics TEXT,
            synced_lyrics TEXT,
            source TEXT NOT NULL,
            source_url TEXT,
            lrclib_id INT,
            is_instrumental BOOLEAN DEFAULT FALSE NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE images (
            id UUID NOT NULL,
            path TEXT NOT NULL,
            extension TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            blurhash TEXT,
            public_id TEXT NOT NULL,
            size INT NOT NULL,
            width INT NOT NULL,
            height INT NOT NULL,
            imageable_type TEXT NOT NULL,
            album_id UUID,
            artist_id UUID,
            playlist_id UUID,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE playlists (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            user_id UUID NOT NULL,
            name TEXT NOT NULL,
            description TEXT,
            is_public BOOLEAN DEFAULT FALSE NOT NULL,
            is_collaborative BOOLEAN DEFAULT FALSE NOT NULL,
            is_smart BOOLEAN DEFAULT FALSE NOT NULL,
            smart_rules JSONB DEFAULT \'{}\'::jsonb NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE playlist_song (
            id UUID NOT NULL,
            playlist_id UUID NOT NULL,
            song_id UUID NOT NULL,
            "position" INT DEFAULT 0 NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE playlist_collaborators (
            id UUID NOT NULL,
            playlist_id UUID NOT NULL,
            user_id UUID NOT NULL,
            role TEXT DEFAULT \'editor\'::text NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE playlist_statistics (
            id UUID NOT NULL,
            playlist_id UUID NOT NULL,
            views INT DEFAULT 0 NOT NULL,
            plays INT DEFAULT 0 NOT NULL,
            shares INT DEFAULT 0 NOT NULL,
            favorites INT DEFAULT 0 NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE media_activities (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            user_id UUID NOT NULL,
            activity_type TEXT NOT NULL,
            song_id UUID,
            album_id UUID,
            artist_id UUID,
            movie_id UUID,
            play_count INT,
            love BOOLEAN DEFAULT FALSE NOT NULL,
            last_played_at TIMESTAMP(0) WITH TIME ZONE,
            last_platform TEXT,
            last_player TEXT,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE movies (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            library_id UUID NOT NULL,
            title TEXT NOT NULL,
            year INT,
            summary TEXT,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE videos (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            path TEXT NOT NULL,
            hash TEXT NOT NULL,
            duration INT,
            height INT,
            width INT,
            video_bitrate INT,
            framerate INT,
            probe JSONB DEFAULT \'{}\'::jsonb NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE movie_video (
            id UUID NOT NULL,
            movie_id UUID NOT NULL,
            video_id UUID NOT NULL,
            sort_order INT DEFAULT 0 NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE recommendations (
            id UUID NOT NULL,
            user_id UUID,
            name TEXT DEFAULT \'default\'::text NOT NULL,
            source_type TEXT NOT NULL,
            source_id TEXT NOT NULL,
            target_type TEXT NOT NULL,
            target_id TEXT NOT NULL,
            score DOUBLE PRECISION DEFAULT 0 NOT NULL,
            "position" INT,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE recommendation_jobs (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            is_full BOOLEAN NOT NULL,
            user_id UUID,
            status TEXT NOT NULL,
            total_songs INT DEFAULT 0 NOT NULL,
            completed_songs INT DEFAULT 0 NOT NULL,
            current_strategy TEXT DEFAULT \'\'::text NOT NULL,
            strategy_counts JSONB DEFAULT \'{}\'::jsonb NOT NULL,
            fail_reason TEXT,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            metadata JSONB DEFAULT \'{}\'::jsonb NOT NULL,
            original_job_id UUID,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE notifications (
            id UUID NOT NULL,
            public_id VARCHAR(21) NOT NULL,
            user_id UUID NOT NULL,
            category TEXT NOT NULL,
            event_type TEXT NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            is_read BOOLEAN DEFAULT FALSE NOT NULL,
            reference_data JSONB,
            created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NOW() NOT NULL,
            parameters JSONB,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE notification_preferences (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            category TEXT NOT NULL,
            channel TEXT NOT NULL,
            enabled BOOLEAN DEFAULT TRUE NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NOW() NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NOW() NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE push_subscriptions (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            endpoint TEXT NOT NULL,
            public_key TEXT NOT NULL,
            auth_key TEXT NOT NULL,
            content_encoding TEXT NOT NULL,
            user_agent TEXT,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE webhooks (
            id UUID NOT NULL,
            url TEXT NOT NULL,
            category_filter JSONB,
            secret_hash TEXT NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE webhook_delivery_logs (
            id UUID NOT NULL,
            webhook_id UUID NOT NULL,
            notification_id TEXT NOT NULL,
            status TEXT NOT NULL,
            http_status_code INT,
            attempt INT NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE job_monitors (
            id UUID NOT NULL,
            job_uuid UUID,
            job_id TEXT NOT NULL,
            name TEXT,
            queue TEXT,
            status TEXT DEFAULT \'queued\'::text NOT NULL,
            queued_at TIMESTAMP(0) WITH TIME ZONE,
            started_at TIMESTAMP(0) WITH TIME ZONE,
            finished_at TIMESTAMP(0) WITH TIME ZONE,
            attempt INT DEFAULT 0 NOT NULL,
            retried BOOLEAN DEFAULT FALSE NOT NULL,
            progress INT,
            exception JSONB,
            exception_class TEXT,
            data TEXT,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            data_truncated BOOLEAN DEFAULT FALSE NOT NULL,
            audit_log TEXT,
            CONSTRAINT chk_job_monitors_status CHECK ((status = ANY (ARRAY[\'queued\'::text, \'running\'::text, \'finished\'::text, \'failed\'::text, \'cancelled\'::text]))),
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE transcode_jobs (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            video_id UUID NOT NULL,
            quality_tier_name TEXT NOT NULL,
            status TEXT DEFAULT \'pending\'::text NOT NULL,
            reference_count INT DEFAULT 0 NOT NULL,
            total_segments INT DEFAULT 0 NOT NULL,
            completed_segments INT DEFAULT 0 NOT NULL,
            output_directory TEXT DEFAULT \'\'::text NOT NULL,
            init_segment_path TEXT,
            segment_map JSONB DEFAULT \'{}\'::jsonb NOT NULL,
            probe_data JSONB DEFAULT \'{}\'::jsonb NOT NULL,
            video_codec TEXT,
            audio_codec TEXT,
            video_bitrate INT DEFAULT 0 NOT NULL,
            audio_bitrate INT DEFAULT 0 NOT NULL,
            width INT DEFAULT 0 NOT NULL,
            height INT DEFAULT 0 NOT NULL,
            framerate DOUBLE PRECISION DEFAULT 0 NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            fail_reason TEXT,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE transcode_sessions (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            user_id UUID NOT NULL,
            job_id UUID NOT NULL,
            video_id UUID NOT NULL,
            state TEXT DEFAULT \'pending\'::text NOT NULL,
            priority TEXT DEFAULT \'normal\'::text NOT NULL,
            audio_profile JSONB DEFAULT \'{}\'::jsonb NOT NULL,
            current_segment_index INT DEFAULT 0 NOT NULL,
            wall_clock_offset DOUBLE PRECISION DEFAULT 0 NOT NULL,
            metrics JSONB DEFAULT \'{}\'::jsonb NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE party_sessions (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            host_user_id UUID NOT NULL,
            video_id UUID NOT NULL,
            transcode_job_id UUID NOT NULL,
            max_members INT DEFAULT 10 NOT NULL,
            playback_state TEXT DEFAULT \'stopped\'::text NOT NULL,
            wall_clock_position DOUBLE PRECISION DEFAULT 0 NOT NULL,
            playback_started_at TIMESTAMP(0) WITH TIME ZONE,
            paused_at_position DOUBLE PRECISION,
            is_active BOOLEAN DEFAULT TRUE NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE party_members (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            user_id UUID NOT NULL,
            session_id UUID NOT NULL,
            role TEXT DEFAULT \'member\'::text NOT NULL,
            audio_profile_id TEXT,
            subtitle_track_id TEXT,
            last_sync_position DOUBLE PRECISION DEFAULT 0 NOT NULL,
            last_sync_at TIMESTAMP(0) WITH TIME ZONE,
            jitter_compensation DOUBLE PRECISION DEFAULT 0 NOT NULL,
            is_connected BOOLEAN DEFAULT TRUE NOT NULL,
            joined_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE radio_sources (
            id UUID NOT NULL,
            name TEXT NOT NULL,
            type TEXT NOT NULL,
            sync_url TEXT NOT NULL,
            sync_config JSONB DEFAULT \'{}\'::jsonb NOT NULL,
            sync_schedule TEXT,
            is_active BOOLEAN DEFAULT TRUE NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE radio_stations (
            id UUID NOT NULL,
            source_id UUID NOT NULL,
            external_id TEXT NOT NULL,
            name TEXT NOT NULL,
            country TEXT NOT NULL,
            language TEXT,
            genres JSONB DEFAULT \'[]\'::jsonb NOT NULL,
            tags JSONB DEFAULT \'[]\'::jsonb NOT NULL,
            streams JSONB DEFAULT \'[]\'::jsonb NOT NULL,
            logo TEXT,
            website TEXT,
            last_checked_at TIMESTAMP(0) WITH TIME ZONE,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE radio_sessions (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            active_station_id UUID,
            active_stream_url TEXT,
            state TEXT DEFAULT \'stopped\'::text NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE starred_stations (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            station_id UUID NOT NULL,
            starred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE country_subscriptions (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            source_id UUID NOT NULL,
            country_code TEXT NOT NULL,
            last_synced_at TIMESTAMP(0) WITH TIME ZONE,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE user_libraries (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            library_id UUID NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE user_library_access (
            user_id UUID NOT NULL,
            library_id UUID NOT NULL,
            granted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NOW() NOT NULL,
            PRIMARY KEY (user_id, library_id)
        )');

        $this->addSql('CREATE TABLE third_party_credentials (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            user_id UUID NOT NULL,
            provider TEXT NOT NULL,
            expires_at TIMESTAMP(0) WITH TIME ZONE,
            meta JSONB DEFAULT \'{}\'::jsonb NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE devices (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            device_id UUID NOT NULL,
            name TEXT,
            last_seen_at TIMESTAMP(0) WITH TIME ZONE,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE listening_sessions (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            active_device_id UUID,
            queue JSONB DEFAULT \'[]\'::jsonb NOT NULL,
            current_track_index INT DEFAULT 0 NOT NULL,
            "position" DOUBLE PRECISION DEFAULT 0 NOT NULL,
            playback_state TEXT DEFAULT \'stopped\'::text NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            last_used_at TIMESTAMP(0) WITH TIME ZONE,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE audio_preferences (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            payload JSONB NOT NULL,
            version INT DEFAULT 1 NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE player_preferences (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            payload JSONB NOT NULL,
            version INT DEFAULT 1 NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE layout_preferences (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            payload JSONB NOT NULL,
            version INT DEFAULT 1 NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE user_sidebar_configs (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            items JSONB DEFAULT \'[]\'::jsonb NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            media_type TEXT DEFAULT \'music\'::text NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE user_accent_colors (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            accent_color TEXT DEFAULT \'violet\'::text NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE eq_device_profiles (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            name TEXT NOT NULL,
            icon TEXT DEFAULT \'custom\'::text NOT NULL,
            device_id TEXT,
            payload JSONB DEFAULT \'{}\'::jsonb NOT NULL,
            is_default BOOLEAN DEFAULT FALSE NOT NULL,
            sort_order INT DEFAULT 0 NOT NULL,
            version INT DEFAULT 1 NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NOW() NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NOW() NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE preference_history (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            preference_type TEXT NOT NULL,
            version INT NOT NULL,
            payload JSONB NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE system_settings (
            key TEXT NOT NULL,
            value JSONB NOT NULL,
            updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW() NOT NULL,
            PRIMARY KEY (key)
        )');

        // doctrine_migration_versions is managed by Doctrine, not created here

        // Indexes
        $this->addSql('CREATE UNIQUE INDEX oauth_access_tokens_token_id_unique ON oauth_access_tokens (token_id)');
        $this->addSql('CREATE INDEX idx_oauth_access_tokens_chain_id ON oauth_access_tokens (chain_id)');
        $this->addSql('CREATE INDEX idx_oauth_access_tokens_client_id ON oauth_access_tokens (client_id)');
        $this->addSql('CREATE INDEX idx_oauth_access_tokens_user_id ON oauth_access_tokens (user_id)');

        $this->addSql('CREATE UNIQUE INDEX oauth_auth_codes_code_id_unique ON oauth_auth_codes (code_id)');

        $this->addSql('CREATE UNIQUE INDEX oauth_clients_public_id_unique ON oauth_clients (public_id)');
        $this->addSql('CREATE INDEX idx_oauth_clients_public_id_trgm ON oauth_clients USING gin (public_id gin_trgm_ops)');

        $this->addSql('CREATE UNIQUE INDEX oauth_device_codes_device_code_unique ON oauth_device_codes (device_code)');
        $this->addSql('CREATE INDEX idx_oauth_device_codes_user_code ON oauth_device_codes (user_code)');

        $this->addSql('CREATE UNIQUE INDEX oauth_refresh_tokens_token_id_unique ON oauth_refresh_tokens (token_id)');
        $this->addSql('CREATE INDEX idx_oauth_refresh_tokens_access_token_id ON oauth_refresh_tokens (access_token_id)');
        $this->addSql('CREATE INDEX idx_oauth_refresh_tokens_chain_id ON oauth_refresh_tokens (chain_id)');

        $this->addSql('CREATE UNIQUE INDEX oauth_token_metadata_token_id_unique ON oauth_token_metadata (token_id)');

        $this->addSql('CREATE UNIQUE INDEX users_email_unique ON users (email)');
        $this->addSql('CREATE UNIQUE INDEX users_public_id_unique ON users (public_id)');
        $this->addSql('CREATE INDEX idx_users_public_id_trgm ON users USING gin (public_id gin_trgm_ops)');

        $this->addSql('CREATE UNIQUE INDEX passkeys_credential_id_unique ON passkeys (credential_id)');
        $this->addSql('CREATE INDEX idx_passkeys_user_id ON passkeys (user_id)');

        $this->addSql('CREATE UNIQUE INDEX libraries_slug_unique ON libraries (slug)');

        $this->addSql('CREATE UNIQUE INDEX artists_public_id_unique ON artists (public_id)');
        $this->addSql('CREATE INDEX idx_artists_name ON artists (name)');
        $this->addSql('CREATE INDEX idx_artists_name_pgroonga ON artists USING pgroonga (name) WITH (plugins=\'token_filters/stem\', tokenizer=\'TokenNgram\', normalizer=\'NormalizerAuto\', token_filters=\'TokenFilterStem\')');
        $this->addSql('CREATE INDEX idx_artists_public_id_trgm ON artists USING gin (public_id gin_trgm_ops)');

        $this->addSql('CREATE UNIQUE INDEX albums_public_id_unique ON albums (public_id)');
        $this->addSql('CREATE INDEX idx_albums_library_id ON albums (library_id)');
        $this->addSql('CREATE INDEX idx_albums_title ON albums (title)');
        $this->addSql('CREATE INDEX idx_albums_cover_image_null ON albums (id) WHERE cover_image_id IS NULL');
        $this->addSql('CREATE INDEX idx_albums_public_id_trgm ON albums USING gin (public_id gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_albums_title_pgroonga ON albums USING pgroonga (title) WITH (plugins=\'token_filters/stem\', tokenizer=\'TokenNgram\', normalizer=\'NormalizerAuto\', token_filters=\'TokenFilterStem\')');

        $this->addSql('CREATE UNIQUE INDEX songs_public_id_unique ON songs (public_id)');
        $this->addSql('CREATE INDEX idx_songs_album_id ON songs (album_id)');
        $this->addSql('CREATE INDEX idx_songs_title ON songs (title)');
        $this->addSql('CREATE INDEX idx_songs_title_id ON songs (title, id)');
        $this->addSql('CREATE INDEX idx_songs_hash ON songs (hash)');
        $this->addSql('CREATE INDEX idx_songs_public_id_trgm ON songs USING gin (public_id gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_songs_title_pgroonga ON songs USING pgroonga (title) WITH (plugins=\'token_filters/stem\', tokenizer=\'TokenNgram\', normalizer=\'NormalizerAuto\', token_filters=\'TokenFilterStem\')');

        $this->addSql('CREATE UNIQUE INDEX genres_slug_unique ON genres (slug)');
        $this->addSql('CREATE UNIQUE INDEX genres_name_lower_unique ON genres (lower(name))');
        $this->addSql('CREATE INDEX idx_genres_name_pgroonga ON genres USING pgroonga (name) WITH (plugins=\'token_filters/stem\', tokenizer=\'TokenNgram\', normalizer=\'NormalizerAuto\', token_filters=\'TokenFilterStem\')');

        $this->addSql('CREATE UNIQUE INDEX artist_album_role_unique ON artist_album (artist_id, album_id, role)');
        $this->addSql('CREATE INDEX idx_artist_album_artist_id ON artist_album (artist_id)');
        $this->addSql('CREATE INDEX idx_artist_album_album_id ON artist_album (album_id)');

        $this->addSql('CREATE UNIQUE INDEX artist_song_role_unique ON artist_song (artist_id, song_id, role)');
        $this->addSql('CREATE INDEX idx_artist_song_artist_id ON artist_song (artist_id)');
        $this->addSql('CREATE INDEX idx_artist_song_song_id ON artist_song (song_id)');

        $this->addSql('CREATE UNIQUE INDEX genre_album_unique ON genre_album (genre_id, album_id)');
        $this->addSql('CREATE INDEX idx_genre_album_genre_id ON genre_album (genre_id)');
        $this->addSql('CREATE INDEX idx_genre_album_album_id ON genre_album (album_id)');

        $this->addSql('CREATE UNIQUE INDEX genre_song_unique ON genre_song (genre_id, song_id)');
        $this->addSql('CREATE INDEX idx_genre_song_song_id ON genre_song (song_id)');

        $this->addSql('CREATE UNIQUE INDEX lyrics_song_id_unique ON lyrics (song_id)');
        $this->addSql('CREATE UNIQUE INDEX lyrics_lrclib_id_unique ON lyrics (lrclib_id)');
        $this->addSql('CREATE INDEX idx_lyrics_song_id ON lyrics (song_id)');

        $this->addSql('CREATE UNIQUE INDEX images_public_id_unique ON images (public_id)');
        $this->addSql('CREATE INDEX idx_images_imageable ON images (imageable_type, album_id, artist_id, playlist_id)');
        $this->addSql('CREATE INDEX idx_images_album_id ON images (album_id)');
        $this->addSql('CREATE INDEX idx_images_artist_id ON images (artist_id)');
        $this->addSql('CREATE INDEX idx_images_playlist_id ON images (playlist_id)');
        $this->addSql('CREATE INDEX idx_images_public_id_trgm ON images USING gin (public_id gin_trgm_ops)');

        $this->addSql('CREATE UNIQUE INDEX playlists_public_id_unique ON playlists (public_id)');
        $this->addSql('CREATE INDEX idx_playlists_user_id ON playlists (user_id)');
        $this->addSql('CREATE INDEX idx_playlists_public_id_trgm ON playlists USING gin (public_id gin_trgm_ops)');

        $this->addSql('CREATE UNIQUE INDEX playlist_song_unique ON playlist_song (playlist_id, song_id)');
        $this->addSql('CREATE INDEX idx_playlist_song_playlist_id ON playlist_song (playlist_id)');
        $this->addSql('CREATE INDEX idx_playlist_song_song_id ON playlist_song (song_id)');

        $this->addSql('CREATE UNIQUE INDEX playlist_user_unique ON playlist_collaborators (playlist_id, user_id)');

        $this->addSql('CREATE UNIQUE INDEX media_activities_public_id_unique ON media_activities (public_id)');
        $this->addSql('CREATE INDEX idx_media_activities_user_id ON media_activities (user_id)');
        $this->addSql('CREATE INDEX idx_media_activities_type_user ON media_activities (activity_type, user_id)');
        $this->addSql('CREATE INDEX idx_media_activities_song_id ON media_activities (song_id)');
        $this->addSql('CREATE INDEX idx_media_activities_album_id ON media_activities (album_id)');
        $this->addSql('CREATE INDEX idx_media_activities_artist_id ON media_activities (artist_id)');
        $this->addSql('CREATE INDEX idx_media_activities_movie_id ON media_activities (movie_id)');
        $this->addSql('CREATE INDEX idx_media_activities_public_id_trgm ON media_activities USING gin (public_id gin_trgm_ops)');

        $this->addSql('CREATE UNIQUE INDEX movies_public_id_unique ON movies (public_id)');
        $this->addSql('CREATE INDEX idx_movies_library_id ON movies (library_id)');
        $this->addSql('CREATE INDEX idx_movies_public_id_trgm ON movies USING gin (public_id gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_movies_title_pgroonga ON movies USING pgroonga (title) WITH (plugins=\'token_filters/stem\', tokenizer=\'TokenNgram\', normalizer=\'NormalizerAuto\', token_filters=\'TokenFilterStem\')');

        $this->addSql('CREATE UNIQUE INDEX videos_hash_unique ON videos (hash)');
        $this->addSql('CREATE UNIQUE INDEX videos_public_id_unique ON videos (public_id)');

        $this->addSql('CREATE INDEX idx_recommendations_user_id ON recommendations (user_id)');
        $this->addSql('CREATE INDEX idx_recommendations_source ON recommendations (source_type, source_id)');
        $this->addSql('CREATE INDEX idx_recommendations_target ON recommendations (target_type, target_id)');

        $this->addSql('CREATE UNIQUE INDEX recommendation_jobs_public_id_idx ON recommendation_jobs (public_id)');
        $this->addSql('CREATE INDEX recommendation_jobs_status_idx ON recommendation_jobs (status)');
        $this->addSql('CREATE INDEX recommendation_jobs_user_id_idx ON recommendation_jobs (user_id)');

        $this->addSql('CREATE UNIQUE INDEX notifications_public_id_key ON notifications (public_id)');
        $this->addSql('CREATE INDEX idx_notifications_user_created ON notifications (user_id, created_at DESC)');
        $this->addSql('CREATE INDEX idx_notifications_user_read ON notifications (user_id, is_read)');

        $this->addSql('CREATE UNIQUE INDEX notification_preferences_user_id_category_channel_key ON notification_preferences (user_id, category, channel)');

        $this->addSql('CREATE UNIQUE INDEX idx_push_subscriptions_endpoint ON push_subscriptions (endpoint)');
        $this->addSql('CREATE INDEX idx_push_subscriptions_user_id ON push_subscriptions (user_id)');

        $this->addSql('CREATE INDEX idx_webhook_delivery_logs_webhook_id ON webhook_delivery_logs (webhook_id)');

        $this->addSql('CREATE INDEX idx_job_monitors_job_id ON job_monitors (job_id)');
        $this->addSql('CREATE INDEX idx_job_monitors_name_created_at ON job_monitors (name, created_at)');
        $this->addSql('CREATE INDEX idx_job_monitors_queue_created_at ON job_monitors (queue, created_at)');
        $this->addSql('CREATE INDEX idx_job_monitors_status ON job_monitors (status)');
        $this->addSql('CREATE INDEX idx_job_monitors_status_created_at ON job_monitors (status, created_at)');

        $this->addSql('CREATE UNIQUE INDEX transcode_jobs_public_id_key ON transcode_jobs (public_id)');
        $this->addSql('CREATE UNIQUE INDEX transcode_jobs_video_id_quality_tier_name_key ON transcode_jobs (video_id, quality_tier_name)');
        $this->addSql('CREATE INDEX idx_transcode_jobs_status ON transcode_jobs (status)');
        $this->addSql('CREATE INDEX idx_transcode_jobs_video_id ON transcode_jobs (video_id)');

        $this->addSql('CREATE UNIQUE INDEX transcode_sessions_public_id_key ON transcode_sessions (public_id)');
        $this->addSql('CREATE INDEX idx_transcode_sessions_job_id ON transcode_sessions (job_id)');
        $this->addSql('CREATE INDEX idx_transcode_sessions_state ON transcode_sessions (state)');
        $this->addSql('CREATE INDEX idx_transcode_sessions_user_id ON transcode_sessions (user_id)');

        $this->addSql('CREATE UNIQUE INDEX party_sessions_public_id_key ON party_sessions (public_id)');
        $this->addSql('CREATE INDEX idx_party_sessions_is_active ON party_sessions (is_active)');
        $this->addSql('CREATE INDEX idx_party_sessions_video_id ON party_sessions (video_id)');

        $this->addSql('CREATE UNIQUE INDEX party_members_public_id_key ON party_members (public_id)');
        $this->addSql('CREATE UNIQUE INDEX party_members_user_id_session_id_key ON party_members (user_id, session_id)');
        $this->addSql('CREATE INDEX idx_party_members_session_id ON party_members (session_id)');
        $this->addSql('CREATE INDEX idx_party_members_user_id ON party_members (user_id)');

        $this->addSql('CREATE UNIQUE INDEX radio_stations_source_id_external_id_key ON radio_stations (source_id, external_id)');
        $this->addSql('CREATE INDEX idx_radio_stations_country ON radio_stations (country)');
        $this->addSql('CREATE INDEX idx_radio_stations_source_country ON radio_stations (source_id, country)');

        $this->addSql('CREATE UNIQUE INDEX radio_sessions_user_id_key ON radio_sessions (user_id)');

        $this->addSql('CREATE UNIQUE INDEX starred_stations_user_id_station_id_key ON starred_stations (user_id, station_id)');
        $this->addSql('CREATE INDEX idx_starred_stations_user ON starred_stations (user_id)');

        $this->addSql('CREATE UNIQUE INDEX country_subscriptions_user_id_source_id_country_code_key ON country_subscriptions (user_id, source_id, country_code)');

        $this->addSql('CREATE UNIQUE INDEX user_libraries_user_library_unique ON user_libraries (user_id, library_id)');
        $this->addSql('CREATE INDEX idx_user_libraries_user_id ON user_libraries (user_id)');
        $this->addSql('CREATE INDEX idx_user_libraries_library_id ON user_libraries (library_id)');

        $this->addSql('CREATE UNIQUE INDEX third_party_credentials_public_id_unique ON third_party_credentials (public_id)');
        $this->addSql('CREATE INDEX idx_third_party_credentials_user_id ON third_party_credentials (user_id)');

        $this->addSql('CREATE UNIQUE INDEX devices_user_id_device_id_key ON devices (user_id, device_id)');

        $this->addSql('CREATE UNIQUE INDEX listening_sessions_user_id_key ON listening_sessions (user_id)');

        $this->addSql('CREATE UNIQUE INDEX audio_preferences_user_id_key ON audio_preferences (user_id)');

        $this->addSql('CREATE UNIQUE INDEX player_preferences_user_id_key ON player_preferences (user_id)');

        $this->addSql('CREATE UNIQUE INDEX layout_preferences_user_id_key ON layout_preferences (user_id)');

        $this->addSql('CREATE UNIQUE INDEX uniq_user_media ON user_sidebar_configs (user_id, media_type)');
        $this->addSql('CREATE UNIQUE INDEX user_sidebar_configs_user_id_key ON user_sidebar_configs (user_id)');

        $this->addSql('CREATE UNIQUE INDEX user_accent_colors_user_id_key ON user_accent_colors (user_id)');

        $this->addSql('CREATE UNIQUE INDEX eq_device_profiles_user_id_name_key ON eq_device_profiles (user_id, name)');

        $this->addSql('CREATE INDEX idx_pref_history_user_type_version ON preference_history (user_id, preference_type, version)');

        // Foreign Keys
        $this->addSql('ALTER TABLE oauth_access_tokens ADD CONSTRAINT oauth_access_tokens_client_id_fkey FOREIGN KEY (client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth_access_tokens ADD CONSTRAINT oauth_access_tokens_user_id_fkey FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE oauth_auth_codes ADD CONSTRAINT oauth_auth_codes_client_id_fkey FOREIGN KEY (client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth_auth_codes ADD CONSTRAINT oauth_auth_codes_user_id_fkey FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE oauth_device_codes ADD CONSTRAINT oauth_device_codes_client_id_fkey FOREIGN KEY (client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth_device_codes ADD CONSTRAINT oauth_device_codes_user_id_fkey FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE oauth_refresh_tokens ADD CONSTRAINT oauth_refresh_tokens_access_token_id_fkey FOREIGN KEY (access_token_id) REFERENCES oauth_access_tokens (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth_refresh_tokens ADD CONSTRAINT oauth_refresh_tokens_previous_refresh_token_id_fkey FOREIGN KEY (previous_refresh_token_id) REFERENCES oauth_refresh_tokens (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE oauth_token_metadata ADD CONSTRAINT oauth_token_metadata_token_id_fkey FOREIGN KEY (token_id) REFERENCES oauth_access_tokens (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE passkeys ADD CONSTRAINT passkeys_user_id_fkey FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE artists ADD CONSTRAINT fk_artists_cover_image_id FOREIGN KEY (cover_image_id) REFERENCES images (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE albums ADD CONSTRAINT albums_library_id_fkey FOREIGN KEY (library_id) REFERENCES libraries (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE albums ADD CONSTRAINT fk_albums_cover_image FOREIGN KEY (cover_image_id) REFERENCES images (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE songs ADD CONSTRAINT songs_album_id_fkey FOREIGN KEY (album_id) REFERENCES albums (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE genres ADD CONSTRAINT genres_parent_id_fkey FOREIGN KEY (parent_id) REFERENCES genres (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE artist_album ADD CONSTRAINT artist_album_artist_id_fkey FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE artist_album ADD CONSTRAINT artist_album_album_id_fkey FOREIGN KEY (album_id) REFERENCES albums (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE artist_song ADD CONSTRAINT artist_song_artist_id_fkey FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE artist_song ADD CONSTRAINT artist_song_song_id_fkey FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE genre_album ADD CONSTRAINT genre_album_genre_id_fkey FOREIGN KEY (genre_id) REFERENCES genres (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE genre_album ADD CONSTRAINT genre_album_album_id_fkey FOREIGN KEY (album_id) REFERENCES albums (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE genre_song ADD CONSTRAINT genre_song_genre_id_fkey FOREIGN KEY (genre_id) REFERENCES genres (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE genre_song ADD CONSTRAINT genre_song_song_id_fkey FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE lyrics ADD CONSTRAINT lyrics_song_id_fkey FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE images ADD CONSTRAINT images_album_id_fkey FOREIGN KEY (album_id) REFERENCES albums (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE images ADD CONSTRAINT images_artist_id_fkey FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE images ADD CONSTRAINT images_playlist_id_fkey FOREIGN KEY (playlist_id) REFERENCES playlists (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE playlists ADD CONSTRAINT playlists_user_id_fkey FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE playlist_song ADD CONSTRAINT playlist_song_playlist_id_fkey FOREIGN KEY (playlist_id) REFERENCES playlists (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE playlist_song ADD CONSTRAINT playlist_song_song_id_fkey FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE playlist_collaborators ADD CONSTRAINT playlist_collaborators_playlist_id_fkey FOREIGN KEY (playlist_id) REFERENCES playlists (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE playlist_collaborators ADD CONSTRAINT playlist_collaborators_user_id_fkey FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE playlist_statistics ADD CONSTRAINT playlist_statistics_playlist_id_fkey FOREIGN KEY (playlist_id) REFERENCES playlists (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE media_activities ADD CONSTRAINT media_activities_user_id_fkey FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE media_activities ADD CONSTRAINT media_activities_song_id_fkey FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE media_activities ADD CONSTRAINT media_activities_album_id_fkey FOREIGN KEY (album_id) REFERENCES albums (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE media_activities ADD CONSTRAINT media_activities_artist_id_fkey FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE media_activities ADD CONSTRAINT media_activities_movie_id_fkey FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE movies ADD CONSTRAINT movies_library_id_fkey FOREIGN KEY (library_id) REFERENCES libraries (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE movie_video ADD CONSTRAINT movie_video_movie_id_fkey FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE movie_video ADD CONSTRAINT movie_video_video_id_fkey FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE recommendations ADD CONSTRAINT recommendations_user_id_fkey FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT fk_notifications_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE notification_preferences ADD CONSTRAINT fk_notification_preferences_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE push_subscriptions ADD CONSTRAINT _fkpush_subscriptions_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE webhook_delivery_logs ADD CONSTRAINT fk_2afcb9d15c9ba60b FOREIGN KEY (webhook_id) REFERENCES webhooks (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE transcode_sessions ADD CONSTRAINT fk_transcode_session_job FOREIGN KEY (job_id) REFERENCES transcode_jobs (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transcode_sessions ADD CONSTRAINT fk_transcode_sessions_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE party_sessions ADD CONSTRAINT fk_party_sessions_host_user_id FOREIGN KEY (host_user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE party_members ADD CONSTRAINT fk_party_members_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE radio_stations ADD CONSTRAINT radio_stations_source_id_fkey FOREIGN KEY (source_id) REFERENCES radio_sources (id)');

        $this->addSql('ALTER TABLE radio_sessions ADD CONSTRAINT fk_radio_sessions_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE radio_sessions ADD CONSTRAINT radio_sessions_active_station_id_fkey FOREIGN KEY (active_station_id) REFERENCES radio_stations (id)');

        $this->addSql('ALTER TABLE starred_stations ADD CONSTRAINT fk_starred_stations_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE starred_stations ADD CONSTRAINT starred_stations_station_id_fkey FOREIGN KEY (station_id) REFERENCES radio_stations (id)');

        $this->addSql('ALTER TABLE country_subscriptions ADD CONSTRAINT fk_country_subscriptions_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE country_subscriptions ADD CONSTRAINT country_subscriptions_source_id_fkey FOREIGN KEY (source_id) REFERENCES radio_sources (id)');

        $this->addSql('ALTER TABLE user_libraries ADD CONSTRAINT user_libraries_user_id_fkey FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_libraries ADD CONSTRAINT user_libraries_library_id_fkey FOREIGN KEY (library_id) REFERENCES libraries (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE user_library_access ADD CONSTRAINT fk_user_library_access_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_library_access ADD CONSTRAINT fk_user_library_access_library FOREIGN KEY (library_id) REFERENCES libraries (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE third_party_credentials ADD CONSTRAINT third_party_credentials_user_id_fkey FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE devices ADD CONSTRAINT fk_devices_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE listening_sessions ADD CONSTRAINT fk_listening_sessions_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE audio_preferences ADD CONSTRAINT fk_audio_preferences_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE player_preferences ADD CONSTRAINT fk_player_preferences_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE layout_preferences ADD CONSTRAINT fk_layout_preferences_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE user_sidebar_configs ADD CONSTRAINT fk_user_sidebar_configs_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE user_accent_colors ADD CONSTRAINT fk_user_accent_colors_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE eq_device_profiles ADD CONSTRAINT fk_eq_device_profiles_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE preference_history ADD CONSTRAINT fk_preference_history_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Drop all tables in reverse order of creation
        // doctrine_migration_versions is managed by Doctrine, not dropped here
        $this->addSql('DROP TABLE IF EXISTS system_settings CASCADE');
        $this->addSql('DROP TABLE IF EXISTS preference_history CASCADE');
        $this->addSql('DROP TABLE IF EXISTS eq_device_profiles CASCADE');
        $this->addSql('DROP TABLE IF EXISTS user_accent_colors CASCADE');
        $this->addSql('DROP TABLE IF EXISTS user_sidebar_configs CASCADE');
        $this->addSql('DROP TABLE IF EXISTS layout_preferences CASCADE');
        $this->addSql('DROP TABLE IF EXISTS player_preferences CASCADE');
        $this->addSql('DROP TABLE IF EXISTS audio_preferences CASCADE');
        $this->addSql('DROP TABLE IF EXISTS listening_sessions CASCADE');
        $this->addSql('DROP TABLE IF EXISTS devices CASCADE');
        $this->addSql('DROP TABLE IF EXISTS third_party_credentials CASCADE');
        $this->addSql('DROP TABLE IF EXISTS user_library_access CASCADE');
        $this->addSql('DROP TABLE IF EXISTS user_libraries CASCADE');
        $this->addSql('DROP TABLE IF EXISTS country_subscriptions CASCADE');
        $this->addSql('DROP TABLE IF EXISTS starred_stations CASCADE');
        $this->addSql('DROP TABLE IF EXISTS radio_sessions CASCADE');
        $this->addSql('DROP TABLE IF EXISTS radio_stations CASCADE');
        $this->addSql('DROP TABLE IF EXISTS radio_sources CASCADE');
        $this->addSql('DROP TABLE IF EXISTS party_members CASCADE');
        $this->addSql('DROP TABLE IF EXISTS party_sessions CASCADE');
        $this->addSql('DROP TABLE IF EXISTS transcode_sessions CASCADE');
        $this->addSql('DROP TABLE IF EXISTS transcode_jobs CASCADE');
        $this->addSql('DROP TABLE IF EXISTS job_monitors CASCADE');
        $this->addSql('DROP TABLE IF EXISTS webhook_delivery_logs CASCADE');
        $this->addSql('DROP TABLE IF EXISTS webhooks CASCADE');
        $this->addSql('DROP TABLE IF EXISTS push_subscriptions CASCADE');
        $this->addSql('DROP TABLE IF EXISTS notification_preferences CASCADE');
        $this->addSql('DROP TABLE IF EXISTS notifications CASCADE');
        $this->addSql('DROP TABLE IF EXISTS recommendation_jobs CASCADE');
        $this->addSql('DROP TABLE IF EXISTS recommendations CASCADE');
        $this->addSql('DROP TABLE IF EXISTS movie_video CASCADE');
        $this->addSql('DROP TABLE IF EXISTS videos CASCADE');
        $this->addSql('DROP TABLE IF EXISTS movies CASCADE');
        $this->addSql('DROP TABLE IF EXISTS media_activities CASCADE');
        $this->addSql('DROP TABLE IF EXISTS playlist_statistics CASCADE');
        $this->addSql('DROP TABLE IF EXISTS playlist_collaborators CASCADE');
        $this->addSql('DROP TABLE IF EXISTS playlist_song CASCADE');
        $this->addSql('DROP TABLE IF EXISTS playlists CASCADE');
        $this->addSql('DROP TABLE IF EXISTS images CASCADE');
        $this->addSql('DROP TABLE IF EXISTS lyrics CASCADE');
        $this->addSql('DROP TABLE IF EXISTS genre_song CASCADE');
        $this->addSql('DROP TABLE IF EXISTS genre_album CASCADE');
        $this->addSql('DROP TABLE IF EXISTS genres CASCADE');
        $this->addSql('DROP TABLE IF EXISTS songs CASCADE');
        $this->addSql('DROP TABLE IF EXISTS albums CASCADE');
        $this->addSql('DROP TABLE IF EXISTS artist_song CASCADE');
        $this->addSql('DROP TABLE IF EXISTS artist_album CASCADE');
        $this->addSql('DROP TABLE IF EXISTS artists CASCADE');
        $this->addSql('DROP TABLE IF EXISTS libraries CASCADE');
        $this->addSql('DROP TABLE IF EXISTS password_reset_tokens CASCADE');
        $this->addSql('DROP TABLE IF EXISTS passkeys CASCADE');
        $this->addSql('DROP TABLE IF EXISTS users CASCADE');
        $this->addSql('DROP TABLE IF EXISTS oauth_token_metadata CASCADE');
        $this->addSql('DROP TABLE IF EXISTS oauth_scopes CASCADE');
        $this->addSql('DROP TABLE IF EXISTS oauth_refresh_tokens CASCADE');
        $this->addSql('DROP TABLE IF EXISTS oauth_device_codes CASCADE');
        $this->addSql('DROP TABLE IF EXISTS oauth_clients CASCADE');
        $this->addSql('DROP TABLE IF EXISTS oauth_auth_codes CASCADE');
        $this->addSql('DROP TABLE IF EXISTS oauth_access_tokens CASCADE');

        $this->addSql('DROP EXTENSION IF EXISTS "uuid-ossp" CASCADE');
        $this->addSql('DROP EXTENSION IF EXISTS pgroonga CASCADE');
        $this->addSql('DROP EXTENSION IF EXISTS pgcrypto CASCADE');
        $this->addSql('DROP EXTENSION IF EXISTS pg_trgm CASCADE');
        $this->addSql('DROP EXTENSION IF EXISTS pg_stat_statements CASCADE');
        $this->addSql('DROP EXTENSION IF EXISTS ltree CASCADE');
        $this->addSql('DROP EXTENSION IF EXISTS citext CASCADE');
    }
}
