<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Shared\Infrastructure\Doctrine\InitialSchemaGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017000000 extends AbstractMigration
{
    public const string PURGE_JOB_ID = '0199bf3c-8a00-7000-8000-0a07c0de9e01';
    public const string PURGE_COMMAND = 'App\\Auth\\Application\\Command\\OAuth\\PurgeExpiredOAuthCodesCommand';
    public const string PURGE_EXPRESSION = '30 3 * * *';
    public const string GENERATION_JOB_ID = '0199bf3c-8a00-7000-8000-0a07c0de9e02';
    public const string GENERATION_COMMAND = 'App\\Recommendation\\Application\\Command\\GenerateRecommendationsCommand';
    public const string GENERATION_EXPRESSION = '0 4 * * *';
    public const string GENERATION_PARAMETERS = '{"mode":"incremental","automatic":true}';

    public function getDescription(): string
    {
        return 'Create the application schema and its daily OAuth purge and recommendation schedules.';
    }

    public function preUp(Schema $schema): void
    {
        InitialSchemaGuard::assertDatabaseEmpty($this->connection);
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS citext');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS ltree');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_stat_statements');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pgcrypto');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pgroonga');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');

        $this->addSql(<<<'SQL'
            CREATE TABLE albums (
                id uuid NOT NULL,
                public_id text NOT NULL,
                library_id uuid NOT NULL,
                title text NOT NULL,
                type text NOT NULL,
                mbid text,
                discogs_id text,
                spotify_id text,
                year integer,
                label text,
                catalog_number text,
                barcode text,
                country text,
                language text,
                disambiguation text,
                annotation text,
                locked_fields jsonb DEFAULT '{}'::jsonb NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                cover_image_id uuid,
                merged_from jsonb DEFAULT '[]'::jsonb NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE artist_album (
                id uuid NOT NULL,
                artist_id uuid NOT NULL,
                album_id uuid NOT NULL,
                role text
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE artist_song (
                id uuid NOT NULL,
                artist_id uuid NOT NULL,
                song_id uuid NOT NULL,
                role text
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE artists (
                id uuid NOT NULL,
                public_id text NOT NULL,
                name text NOT NULL,
                country text,
                gender text,
                type text,
                life_span_begin date,
                life_span_end date,
                disambiguation text,
                sort_name text,
                biography text,
                mbid text,
                discogs_id text,
                spotify_id text,
                locked_fields jsonb DEFAULT '{}'::jsonb NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                cover_image_id uuid
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE audio_preferences (
                id uuid NOT NULL,
                user_id uuid NOT NULL,
                payload jsonb NOT NULL,
                version integer DEFAULT 1 NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE country_subscriptions (
                id uuid NOT NULL,
                user_id uuid NOT NULL,
                source_id uuid NOT NULL,
                country_code text NOT NULL,
                last_synced_at timestamp with time zone,
                created_at timestamp with time zone NOT NULL,
                public_id text NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE devices (
                id uuid NOT NULL,
                user_id uuid NOT NULL,
                device_id uuid NOT NULL,
                name text,
                last_seen_at timestamp with time zone,
                created_at timestamp with time zone NOT NULL,
                public_id text NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE domain_event_outbox (
                id bigint GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                event_class text NOT NULL,
                event_name text NOT NULL,
                payload jsonb NOT NULL,
                created_at timestamp with time zone NOT NULL,
                relayed_at timestamp with time zone DEFAULT NULL::timestamp with time zone,
                attempts integer DEFAULT 0 NOT NULL,
                next_attempt_at timestamp with time zone DEFAULT NULL::timestamp with time zone,
                dead_lettered_at timestamp with time zone DEFAULT NULL::timestamp with time zone,
                lease_token text,
                lease_until timestamp with time zone,
                legacy_review_required boolean DEFAULT false NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE domain_event_outbox_delivery (
                id bigint GENERATED BY DEFAULT AS IDENTITY NOT NULL,
                channel text NOT NULL,
                notification_id text NOT NULL,
                payload text NOT NULL,
                attempts integer DEFAULT 0 NOT NULL,
                next_attempt_at timestamp with time zone,
                dead_lettered_at timestamp with time zone,
                relayed_at timestamp with time zone,
                lease_token text,
                lease_until timestamp with time zone,
                created_at timestamp with time zone DEFAULT now() NOT NULL,
                CONSTRAINT domain_event_outbox_delivery_channel_check CHECK ((channel = ANY (ARRAY['email'::text, 'push'::text, 'webhook'::text])))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE domain_event_outbox_receipt (
                outbox_id bigint NOT NULL,
                consumer text NOT NULL,
                processed_at timestamp with time zone DEFAULT now() NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE email_verification_tokens (
                user_id uuid NOT NULL,
                email citext NOT NULL,
                token_hash text NOT NULL,
                created_at timestamp with time zone NOT NULL,
                expires_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE eq_device_profiles (
                id uuid NOT NULL,
                user_id uuid NOT NULL,
                name text NOT NULL,
                icon text DEFAULT 'custom'::text NOT NULL,
                device_id text,
                payload jsonb DEFAULT '{}'::jsonb NOT NULL,
                is_default boolean DEFAULT false NOT NULL,
                sort_order integer DEFAULT 0 NOT NULL,
                version integer DEFAULT 1 NOT NULL,
                created_at timestamp with time zone DEFAULT now() NOT NULL,
                updated_at timestamp with time zone DEFAULT now() NOT NULL,
                public_id text NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE failed_messages (
                id bigint NOT NULL,
                body text NOT NULL,
                headers text NOT NULL,
                queue_name text NOT NULL,
                created_at timestamp with time zone NOT NULL,
                available_at timestamp with time zone NOT NULL,
                delivered_at timestamp with time zone
            )
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE failed_messages ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
                SEQUENCE NAME failed_messages_id_seq
                START WITH 1
                INCREMENT BY 1
                NO MINVALUE
                NO MAXVALUE
                CACHE 1
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE genre_album (
                id uuid NOT NULL,
                genre_id uuid NOT NULL,
                album_id uuid NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE genre_movie (
                id uuid NOT NULL,
                genre_id uuid NOT NULL,
                movie_id uuid NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE genre_song (
                id uuid NOT NULL,
                genre_id uuid NOT NULL,
                song_id uuid NOT NULL,
                "position" integer
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE genres (
                id uuid NOT NULL,
                parent_id uuid,
                name text NOT NULL,
                slug text NOT NULL,
                mbid text,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                public_id text NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE images (
                id uuid NOT NULL,
                path text NOT NULL,
                extension text NOT NULL,
                mime_type text NOT NULL,
                blurhash text,
                public_id text NOT NULL,
                size integer NOT NULL,
                width integer NOT NULL,
                height integer NOT NULL,
                imageable_type text NOT NULL,
                album_id uuid,
                artist_id uuid,
                playlist_id uuid,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE job_monitors (
                id uuid NOT NULL,
                job_uuid uuid,
                job_id text NOT NULL,
                name text,
                queue text,
                status text DEFAULT 'queued'::text NOT NULL,
                queued_at timestamp with time zone,
                started_at timestamp with time zone,
                finished_at timestamp with time zone,
                attempt integer DEFAULT 0 NOT NULL,
                retried boolean DEFAULT false NOT NULL,
                progress integer,
                exception jsonb,
                exception_class text,
                data text,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                data_truncated boolean DEFAULT false NOT NULL,
                audit_log text,
                duration_microseconds bigint GENERATED ALWAYS AS (((EXTRACT(epoch FROM (finished_at - started_at)) * (1000000)::numeric))::bigint) STORED,
                CONSTRAINT chk_job_monitors_finished_at CHECK ((finished_at >= started_at)),
                CONSTRAINT chk_job_monitors_status CHECK ((status = ANY (ARRAY['queued'::text, 'running'::text, 'finished'::text, 'failed'::text, 'cancelled'::text])))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE layout_preferences (
                id uuid NOT NULL,
                user_id uuid NOT NULL,
                payload jsonb NOT NULL,
                version integer DEFAULT 1 NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE libraries (
                id uuid NOT NULL,
                name text NOT NULL,
                slug text NOT NULL,
                path text NOT NULL,
                type text NOT NULL,
                sort_order integer DEFAULT 0 NOT NULL,
                last_scan timestamp with time zone,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                scan_status text,
                filesystem_type text DEFAULT 'local'::text NOT NULL,
                claim_id uuid,
                claim_expires_at timestamp with time zone,
                claim_kind text,
                public_id text NOT NULL,
                CONSTRAINT chk_libraries_claim CHECK ((((claim_id IS NULL) = (claim_expires_at IS NULL)) AND ((claim_id IS NULL) = (claim_kind IS NULL)) AND ((claim_kind IS NULL) OR (claim_kind = ANY (ARRAY['scan'::text, 'delete'::text]))) AND ((NOT (claim_kind IS DISTINCT FROM 'scan'::text)) = (NOT (scan_status IS DISTINCT FROM 'scanning'::text)))))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE library_file_index (
                id uuid NOT NULL,
                library_id uuid NOT NULL,
                path text NOT NULL,
                hash text NOT NULL,
                size bigint NOT NULL,
                extension text NOT NULL,
                modified_at bigint NOT NULL,
                discovered_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE listening_sessions (
                id uuid NOT NULL,
                user_id uuid NOT NULL,
                active_device_id uuid,
                queue jsonb DEFAULT '[]'::jsonb NOT NULL,
                current_track_index integer DEFAULT 0 NOT NULL,
                "position" double precision DEFAULT 0 NOT NULL,
                playback_state text DEFAULT 'stopped'::text NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                last_used_at timestamp with time zone,
                public_id text NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE login_blocks (
                id uuid NOT NULL,
                ip_address text NOT NULL,
                email text NOT NULL,
                field_value text NOT NULL,
                user_agent text NOT NULL,
                created_at timestamp with time zone NOT NULL,
                public_id text NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE lyrics (
                id uuid NOT NULL,
                song_id uuid NOT NULL,
                plain_lyrics text,
                synced_lyrics text,
                source text NOT NULL,
                source_url text,
                lrclib_id integer,
                is_instrumental boolean DEFAULT false NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE media_activities (
                id uuid NOT NULL,
                public_id text NOT NULL,
                user_id uuid NOT NULL,
                activity_type text NOT NULL,
                song_id uuid,
                album_id uuid,
                artist_id uuid,
                movie_id uuid,
                play_count integer,
                love boolean DEFAULT false NOT NULL,
                last_played_at timestamp with time zone,
                last_platform text,
                last_player text,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE movie_collections (
                id uuid NOT NULL,
                tmdb_collection_id integer NOT NULL,
                name text NOT NULL,
                overview text,
                poster_path text,
                backdrop_path text
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE movie_video (
                id uuid NOT NULL,
                movie_id uuid NOT NULL,
                video_id uuid NOT NULL,
                sort_order integer DEFAULT 0 NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE movies (
                id uuid NOT NULL,
                public_id text NOT NULL,
                library_id uuid NOT NULL,
                title text NOT NULL,
                year integer,
                summary text,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                tmdb_id integer,
                imdb_id text,
                overview text,
                tagline text,
                poster_url text,
                backdrop_url text,
                runtime integer,
                rating double precision,
                original_language text,
                tmdb_collection_id integer,
                collection_name text
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE notification_preferences (
                id uuid NOT NULL,
                user_id uuid NOT NULL,
                category text NOT NULL,
                channel text NOT NULL,
                enabled boolean DEFAULT true NOT NULL,
                created_at timestamp with time zone DEFAULT now() NOT NULL,
                updated_at timestamp with time zone DEFAULT now() NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE notifications (
                id uuid NOT NULL,
                public_id text NOT NULL,
                user_id uuid NOT NULL,
                category text NOT NULL,
                event_type text NOT NULL,
                title text NOT NULL,
                body text NOT NULL,
                is_read boolean DEFAULT false NOT NULL,
                reference_data jsonb,
                created_at timestamp with time zone DEFAULT now() NOT NULL,
                parameters jsonb
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE oauth_access_tokens (
                id uuid NOT NULL,
                chain_id uuid,
                token_id text NOT NULL,
                user_id uuid,
                client_id uuid NOT NULL,
                name text,
                scopes jsonb,
                revoked boolean DEFAULT false NOT NULL,
                expires_at timestamp with time zone,
                last_refreshed_at timestamp with time zone,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                dpop_jkt text
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE oauth_auth_codes (
                id uuid NOT NULL,
                code_id text NOT NULL,
                user_id uuid NOT NULL,
                client_id uuid NOT NULL,
                scopes jsonb,
                revoked boolean DEFAULT false NOT NULL,
                expires_at timestamp with time zone,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                code_challenge text NOT NULL,
                code_challenge_method text NOT NULL,
                redirect_uri text NOT NULL,
                CONSTRAINT chk_oauth_auth_codes_code_challenge_method CHECK ((code_challenge_method = 'S256'::text))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE oauth_clients (
                id uuid NOT NULL,
                public_id text NOT NULL,
                name text NOT NULL,
                secret_hash text,
                provider text,
                redirect text NOT NULL,
                personal_access_client boolean DEFAULT false NOT NULL,
                password_client boolean DEFAULT false NOT NULL,
                device_client boolean DEFAULT false NOT NULL,
                confidential boolean DEFAULT false NOT NULL,
                first_party boolean DEFAULT false NOT NULL,
                revoked boolean DEFAULT false NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                user_id uuid,
                CONSTRAINT chk_oauth_clients_confidential_secret_hash CHECK ((confidential = (secret_hash IS NOT NULL))),
                CONSTRAINT chk_oauth_clients_secret_hash_format CHECK ((secret_hash ~ '^[0-9a-f]{64}$'::text))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE oauth_device_codes (
                id uuid NOT NULL,
                device_code text NOT NULL,
                user_code text NOT NULL,
                user_id uuid,
                client_id uuid NOT NULL,
                scopes jsonb,
                verification_uri text NOT NULL,
                verification_uri_complete text,
                expires_at timestamp with time zone,
                "interval" integer DEFAULT 5 NOT NULL,
                last_polled_at timestamp with time zone,
                approved boolean DEFAULT false NOT NULL,
                denied boolean DEFAULT false NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                consumed_at timestamp with time zone,
                CONSTRAINT chk_oauth_device_codes_approved_user_id CHECK (((NOT approved) OR (user_id IS NOT NULL))),
                CONSTRAINT chk_oauth_device_codes_consumed_approved CHECK (((consumed_at IS NULL) OR approved)),
                CONSTRAINT chk_oauth_device_codes_single_decision CHECK ((NOT (approved AND denied)))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE oauth_refresh_tokens (
                id uuid NOT NULL,
                chain_id uuid,
                previous_refresh_token_id uuid,
                token_id text NOT NULL,
                access_token_id uuid NOT NULL,
                revoked boolean DEFAULT false NOT NULL,
                expires_at timestamp with time zone,
                used_at timestamp with time zone,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE oauth_scopes (
                id text NOT NULL,
                description text NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE oauth_token_metadata (
                id uuid NOT NULL,
                token_id uuid NOT NULL,
                user_agent text,
                device_operating_system text,
                device_name text,
                client_fingerprint text,
                session_id text,
                ip_address text,
                ip_history jsonb DEFAULT '[]'::jsonb NOT NULL,
                ip_change_count integer DEFAULT 0 NOT NULL,
                country_code text,
                city text,
                last_geo_notification_at timestamp with time zone,
                broadcast_token text,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE pairing_sessions (
                id uuid NOT NULL,
                public_id text NOT NULL,
                server_id uuid NOT NULL,
                server_public_id text NOT NULL,
                server_url text NOT NULL,
                server_name text NOT NULL,
                pairing_code text NOT NULL,
                method text NOT NULL,
                expires_at timestamp with time zone DEFAULT NULL::timestamp with time zone,
                completed_at timestamp with time zone DEFAULT NULL::timestamp with time zone,
                expired_at timestamp with time zone DEFAULT NULL::timestamp with time zone,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE party_events (
                id uuid NOT NULL,
                session_id uuid NOT NULL,
                user_id uuid NOT NULL,
                action text NOT NULL,
                "position" double precision,
                occurred_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE party_members (
                id uuid NOT NULL,
                public_id text NOT NULL,
                user_id uuid NOT NULL,
                session_id uuid NOT NULL,
                role text DEFAULT 'member'::text NOT NULL,
                audio_profile_id text,
                subtitle_track_id text,
                last_sync_position double precision DEFAULT 0 NOT NULL,
                last_sync_at timestamp with time zone,
                jitter_compensation double precision DEFAULT 0 NOT NULL,
                is_connected boolean DEFAULT true NOT NULL,
                joined_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE party_sessions (
                id uuid NOT NULL,
                public_id text NOT NULL,
                host_user_id uuid NOT NULL,
                video_id uuid NOT NULL,
                transcode_job_id uuid,
                max_members integer DEFAULT 10 NOT NULL,
                playback_state text DEFAULT 'stopped'::text NOT NULL,
                wall_clock_position double precision DEFAULT 0 NOT NULL,
                playback_started_at timestamp with time zone,
                paused_at_position double precision,
                is_active boolean DEFAULT true NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE passkeys (
                id uuid NOT NULL,
                user_id uuid NOT NULL,
                name text NOT NULL,
                credential_id text NOT NULL,
                data jsonb DEFAULT '{}'::jsonb NOT NULL,
                counter integer DEFAULT 0 NOT NULL,
                last_used_at timestamp with time zone,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                public_id text NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE password_reset_tokens (
                user_id uuid NOT NULL,
                token_hash text NOT NULL,
                created_at timestamp with time zone NOT NULL,
                expires_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE player_preferences (
                id uuid NOT NULL,
                user_id uuid NOT NULL,
                payload jsonb NOT NULL,
                version integer DEFAULT 1 NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE playlist_collaborators (
                id uuid NOT NULL,
                playlist_id uuid NOT NULL,
                user_id uuid NOT NULL,
                role text DEFAULT 'editor'::text NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE playlist_song (
                id uuid NOT NULL,
                playlist_id uuid NOT NULL,
                song_id uuid NOT NULL,
                "position" integer DEFAULT 0 NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE playlist_statistics (
                id uuid NOT NULL,
                playlist_id uuid NOT NULL,
                views integer DEFAULT 0 NOT NULL,
                plays integer DEFAULT 0 NOT NULL,
                shares integer DEFAULT 0 NOT NULL,
                favorites integer DEFAULT 0 NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE playlists (
                id uuid NOT NULL,
                public_id text NOT NULL,
                user_id uuid NOT NULL,
                name text NOT NULL,
                description text,
                is_public boolean DEFAULT false NOT NULL,
                is_collaborative boolean DEFAULT false NOT NULL,
                is_smart boolean DEFAULT false NOT NULL,
                smart_rules jsonb DEFAULT '{}'::jsonb NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE preference_history (
                id uuid NOT NULL,
                user_id uuid NOT NULL,
                preference_type text NOT NULL,
                version integer NOT NULL,
                payload jsonb NOT NULL,
                created_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE push_subscriptions (
                id uuid NOT NULL,
                user_id uuid NOT NULL,
                endpoint text NOT NULL,
                public_key text NOT NULL,
                auth_key text NOT NULL,
                content_encoding text NOT NULL,
                user_agent text,
                created_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE radio_sessions (
                id uuid NOT NULL,
                user_id uuid NOT NULL,
                active_station_id uuid,
                active_stream_url text,
                state text DEFAULT 'stopped'::text NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE radio_sources (
                id uuid NOT NULL,
                name text NOT NULL,
                type text NOT NULL,
                sync_url text NOT NULL,
                sync_config jsonb DEFAULT '{}'::jsonb NOT NULL,
                sync_schedule text,
                is_active boolean DEFAULT true NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                public_id text NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE radio_stations (
                id uuid NOT NULL,
                source_id uuid NOT NULL,
                external_id text NOT NULL,
                name text NOT NULL,
                country text NOT NULL,
                language text,
                genres jsonb DEFAULT '[]'::jsonb NOT NULL,
                tags jsonb DEFAULT '[]'::jsonb NOT NULL,
                streams jsonb DEFAULT '[]'::jsonb NOT NULL,
                logo text,
                website text,
                last_checked_at timestamp with time zone,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                public_id text NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE recommendation_jobs (
                id uuid NOT NULL,
                public_id text NOT NULL,
                is_full boolean NOT NULL,
                user_id uuid,
                status text NOT NULL,
                total_songs integer DEFAULT 0 NOT NULL,
                completed_songs integer DEFAULT 0 NOT NULL,
                current_strategy text DEFAULT ''::text NOT NULL,
                strategy_counts jsonb DEFAULT '{}'::jsonb NOT NULL,
                fail_reason text,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                started_at timestamp with time zone,
                completed_at timestamp with time zone,
                metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
                original_job_id uuid
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE recommendations (
                id uuid NOT NULL,
                user_id uuid,
                name text DEFAULT 'default'::text NOT NULL,
                source_type text NOT NULL,
                source_id text NOT NULL,
                target_type text NOT NULL,
                target_id text NOT NULL,
                score double precision DEFAULT 0 NOT NULL,
                "position" integer,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                public_id text NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE scheduled_jobs (
                id uuid NOT NULL,
                revision uuid NOT NULL,
                recovery_after timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
                evaluated_through timestamp with time zone,
                name text NOT NULL,
                expression text NOT NULL,
                job_type text NOT NULL,
                command text NOT NULL,
                status text DEFAULT 'active'::text NOT NULL,
                description text,
                parameters json DEFAULT '[]'::json NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                last_run_at timestamp with time zone DEFAULT NULL::timestamp with time zone,
                next_run_at timestamp with time zone DEFAULT NULL::timestamp with time zone,
                last_result text,
                run_count integer DEFAULT 0 NOT NULL,
                last_failure_at timestamp with time zone DEFAULT NULL::timestamp with time zone,
                last_error text,
                public_id text NOT NULL,
                CONSTRAINT scheduled_jobs_evaluated_through_check CHECK (((evaluated_through IS NULL) OR (isfinite(evaluated_through) AND (evaluated_through = date_trunc('minute'::text, evaluated_through, 'UTC'::text))))),
                CONSTRAINT scheduled_jobs_recovery_after_check CHECK (isfinite(recovery_after))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE scheduler_occurrence_executions (
                occurrence_id uuid NOT NULL,
                job_id uuid NOT NULL,
                attempt_id uuid NOT NULL,
                deployment_namespace text NOT NULL,
                deployment_boot_id text NOT NULL,
                deployment_epoch bigint NOT NULL,
                started_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
                returned_at timestamp with time zone,
                CONSTRAINT scheduler_occurrence_executions_deployment_boot_id_check CHECK ((deployment_boot_id ~ '^[0-9a-f]{32}$'::text)),
                CONSTRAINT scheduler_occurrence_executions_deployment_epoch_check CHECK ((deployment_epoch > 0)),
                CONSTRAINT scheduler_occurrence_executions_deployment_namespace_check CHECK ((deployment_namespace ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$'::text))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE scheduler_occurrences (
                id uuid NOT NULL,
                job_id uuid NOT NULL,
                scheduled_for timestamp with time zone NOT NULL,
                origin text DEFAULT 'scheduled'::text NOT NULL,
                job_type text NOT NULL,
                command text NOT NULL,
                parameters json NOT NULL,
                dispatch_after timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
                dispatch_token uuid,
                dispatched_at timestamp with time zone,
                created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
                public_id text NOT NULL,
                job_public_id text NOT NULL,
                CONSTRAINT scheduler_occurrences_command_check CHECK (((octet_length(command) >= 1) AND (octet_length(command) <= 512))),
                CONSTRAINT scheduler_occurrences_job_type_check CHECK ((job_type = ANY (ARRAY['messenger'::text, 'console'::text]))),
                CONSTRAINT scheduler_occurrences_origin_check CHECK ((origin = ANY (ARRAY['scheduled'::text, 'manual'::text]))),
                CONSTRAINT scheduler_occurrences_parameters_check CHECK (((json_typeof(parameters) = ANY (ARRAY['object'::text, 'array'::text])) AND (octet_length((parameters)::text) <= 16384))),
                CONSTRAINT scheduler_occurrences_scheduled_for_check CHECK ((scheduled_for = date_trunc('minute'::text, scheduled_for, 'UTC'::text)))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE server_instances (
                id uuid NOT NULL,
                public_id text NOT NULL,
                server_url text NOT NULL,
                name text NOT NULL,
                api_key text NOT NULL,
                version text NOT NULL,
                status text DEFAULT 'online'::text NOT NULL,
                last_heartbeat_at timestamp with time zone DEFAULT NULL::timestamp with time zone,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE songs (
                id uuid NOT NULL,
                public_id text NOT NULL,
                album_id uuid NOT NULL,
                title text NOT NULL,
                path text NOT NULL,
                size bigint NOT NULL,
                mime_type text NOT NULL,
                length double precision,
                lyrics text,
                track integer,
                disc integer,
                year integer,
                comment text,
                hash text,
                bitrate integer,
                sample_rate integer,
                channels integer,
                codec text,
                explicit boolean DEFAULT false NOT NULL,
                energy double precision,
                danceability double precision,
                valence double precision,
                acousticness double precision,
                instrumentalness double precision,
                liveness double precision,
                spechiness double precision,
                loudness double precision,
                mbid text,
                discogs_id text,
                spotify_id text,
                locked_fields jsonb DEFAULT '{}'::jsonb NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE starred_stations (
                id uuid NOT NULL,
                user_id uuid NOT NULL,
                station_id uuid NOT NULL,
                starred_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE system_settings (
                key text NOT NULL,
                value jsonb NOT NULL,
                updated_at timestamp with time zone DEFAULT now() NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE third_party_credentials (
                id uuid NOT NULL,
                public_id text NOT NULL,
                user_id uuid NOT NULL,
                provider text NOT NULL,
                expires_at timestamp with time zone,
                meta jsonb DEFAULT '{}'::jsonb NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                access_token text,
                refresh_token text
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE transcode_jobs (
                id uuid NOT NULL,
                public_id text NOT NULL,
                video_id uuid NOT NULL,
                quality_tier_name text NOT NULL,
                status text DEFAULT 'pending'::text NOT NULL,
                reference_count integer DEFAULT 0 NOT NULL,
                total_segments integer DEFAULT 0 NOT NULL,
                completed_segments integer DEFAULT 0 NOT NULL,
                output_directory text DEFAULT ''::text NOT NULL,
                init_segment_path text,
                segment_map jsonb DEFAULT '{}'::jsonb NOT NULL,
                probe_data jsonb DEFAULT '{}'::jsonb NOT NULL,
                video_codec text,
                audio_codec text,
                video_bitrate integer DEFAULT 0 NOT NULL,
                audio_bitrate integer DEFAULT 0 NOT NULL,
                width integer DEFAULT 0 NOT NULL,
                height integer DEFAULT 0 NOT NULL,
                framerate double precision DEFAULT 0 NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                fail_reason text,
                measured_loudness jsonb DEFAULT '{}'::jsonb NOT NULL,
                audio_track_languages jsonb DEFAULT '[]'::jsonb NOT NULL,
                audio_segment_map jsonb DEFAULT '{}'::jsonb NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE transcode_sessions (
                id uuid NOT NULL,
                public_id text NOT NULL,
                user_id uuid NOT NULL,
                job_id uuid NOT NULL,
                video_id uuid NOT NULL,
                state text DEFAULT 'pending'::text NOT NULL,
                priority text DEFAULT 'normal'::text NOT NULL,
                audio_profile jsonb DEFAULT '{}'::jsonb NOT NULL,
                current_segment_index integer DEFAULT 0 NOT NULL,
                wall_clock_offset double precision DEFAULT 0 NOT NULL,
                metrics jsonb DEFAULT '{}'::jsonb NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE user_accent_colors (
                id uuid NOT NULL,
                user_id uuid NOT NULL,
                accent_color text DEFAULT 'violet'::text NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE user_favorites (
                id uuid NOT NULL,
                public_id text NOT NULL,
                user_id uuid NOT NULL,
                entity_type text NOT NULL,
                entity_public_id text NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE user_library_access (
                user_id uuid NOT NULL,
                library_id uuid NOT NULL,
                granted_at timestamp with time zone DEFAULT now() NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE user_settings (
                user_id uuid NOT NULL,
                key text NOT NULL,
                value jsonb NOT NULL,
                updated_at timestamp with time zone DEFAULT now() NOT NULL,
                CONSTRAINT chk_user_settings_value_scalar CHECK ((jsonb_typeof(value) = ANY (ARRAY['boolean'::text, 'number'::text, 'string'::text])))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE user_sidebar_configs (
                id uuid NOT NULL,
                user_id uuid NOT NULL,
                items jsonb DEFAULT '[]'::jsonb NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                media_type text DEFAULT 'music'::text NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE user_theme_moods (
                id uuid NOT NULL,
                user_id uuid NOT NULL,
                theme_mood text DEFAULT 'dark'::text NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id uuid NOT NULL,
                public_id text NOT NULL,
                name text NOT NULL,
                email citext NOT NULL,
                email_verified_at timestamp with time zone,
                password text NOT NULL,
                totp_secret text,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                roles jsonb DEFAULT '["ROLE_USER"]'::jsonb NOT NULL,
                disabled boolean DEFAULT false NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE videos (
                id uuid NOT NULL,
                public_id text NOT NULL,
                path text NOT NULL,
                hash text NOT NULL,
                duration integer,
                height integer,
                width integer,
                video_bitrate integer,
                framerate integer,
                probe jsonb DEFAULT '{}'::jsonb NOT NULL,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE webhook_delivery_logs (
                id uuid NOT NULL,
                webhook_id uuid NOT NULL,
                notification_id text NOT NULL,
                status text NOT NULL,
                http_status_code integer,
                attempt integer NOT NULL,
                created_at timestamp with time zone NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE webhooks (
                id uuid NOT NULL,
                url text NOT NULL,
                category_filter jsonb,
                created_at timestamp with time zone NOT NULL,
                updated_at timestamp with time zone NOT NULL,
                encrypted_secret text NOT NULL,
                public_id text NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE worker_deployment_containers (
                namespace text NOT NULL,
                boot_id text NOT NULL,
                daemon_id text NOT NULL,
                container_id text NOT NULL,
                created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
                start_claimed_at timestamp with time zone,
                CONSTRAINT worker_deployment_containers_boot_id_check CHECK ((boot_id ~ '^[0-9a-f]{32}$'::text)),
                CONSTRAINT worker_deployment_containers_container_id_check CHECK ((container_id ~ '^[0-9a-f]{64}$'::text)),
                CONSTRAINT worker_deployment_containers_daemon_id_check CHECK ((daemon_id ~ '^[A-Za-z0-9_.:-]{1,128}$'::text)),
                CONSTRAINT worker_deployment_containers_namespace_check CHECK ((namespace ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$'::text))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE worker_deployment_creations (
                namespace text NOT NULL,
                boot_id text NOT NULL,
                daemon_id text NOT NULL,
                recipe_hash text NOT NULL,
                created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
                CONSTRAINT worker_deployment_creations_boot_id_check CHECK ((boot_id ~ '^[0-9a-f]{32}$'::text)),
                CONSTRAINT worker_deployment_creations_daemon_id_check CHECK ((daemon_id ~ '^[A-Za-z0-9_.:-]{1,128}$'::text)),
                CONSTRAINT worker_deployment_creations_namespace_check CHECK ((namespace ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$'::text)),
                CONSTRAINT worker_deployment_creations_recipe_hash_check CHECK ((recipe_hash ~ '^[0-9a-f]{64}$'::text))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE worker_deployment_leases (
                namespace text NOT NULL,
                owner_boot_id text NOT NULL,
                epoch bigint NOT NULL,
                state text NOT NULL,
                expires_at timestamp with time zone NOT NULL,
                CONSTRAINT worker_deployment_leases_epoch_check CHECK ((epoch > 0)),
                CONSTRAINT worker_deployment_leases_namespace_check CHECK ((namespace ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$'::text)),
                CONSTRAINT worker_deployment_leases_owner_boot_id_check CHECK ((owner_boot_id ~ '^[0-9a-f]{32}$'::text)),
                CONSTRAINT worker_deployment_leases_state_check CHECK ((state = ANY (ARRAY['active'::text, 'available'::text])))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE worker_deployment_retirements (
                namespace text NOT NULL,
                boot_id text NOT NULL,
                daemon_id text NOT NULL,
                container_id text NOT NULL,
                intended_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
                completed_at timestamp with time zone,
                CONSTRAINT worker_deployment_retirements_boot_id_check CHECK ((boot_id ~ '^[0-9a-f]{32}$'::text)),
                CONSTRAINT worker_deployment_retirements_container_id_check CHECK ((container_id ~ '^[0-9a-f]{64}$'::text)),
                CONSTRAINT worker_deployment_retirements_daemon_id_check CHECK ((daemon_id ~ '^[A-Za-z0-9_.:-]{1,128}$'::text)),
                CONSTRAINT worker_deployment_retirements_namespace_check CHECK ((namespace ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$'::text))
            )
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY albums
                ADD CONSTRAINT albums_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY artist_album
                ADD CONSTRAINT artist_album_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY artist_song
                ADD CONSTRAINT artist_song_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY artists
                ADD CONSTRAINT artists_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY audio_preferences
                ADD CONSTRAINT audio_preferences_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY country_subscriptions
                ADD CONSTRAINT country_subscriptions_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY devices
                ADD CONSTRAINT devices_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY domain_event_outbox_delivery
                ADD CONSTRAINT domain_event_outbox_delivery_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY domain_event_outbox
                ADD CONSTRAINT domain_event_outbox_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY domain_event_outbox_receipt
                ADD CONSTRAINT domain_event_outbox_receipt_pkey PRIMARY KEY (outbox_id, consumer)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY email_verification_tokens
                ADD CONSTRAINT email_verification_tokens_pkey PRIMARY KEY (user_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY eq_device_profiles
                ADD CONSTRAINT eq_device_profiles_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY failed_messages
                ADD CONSTRAINT failed_messages_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY genre_album
                ADD CONSTRAINT genre_album_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY genre_movie
                ADD CONSTRAINT genre_movie_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY genre_song
                ADD CONSTRAINT genre_song_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY genres
                ADD CONSTRAINT genres_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY images
                ADD CONSTRAINT images_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY job_monitors
                ADD CONSTRAINT job_monitors_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY layout_preferences
                ADD CONSTRAINT layout_preferences_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY libraries
                ADD CONSTRAINT libraries_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY library_file_index
                ADD CONSTRAINT library_file_index_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY listening_sessions
                ADD CONSTRAINT listening_sessions_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY login_blocks
                ADD CONSTRAINT login_blocks_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY lyrics
                ADD CONSTRAINT lyrics_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY media_activities
                ADD CONSTRAINT media_activities_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY movie_collections
                ADD CONSTRAINT movie_collections_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY movie_video
                ADD CONSTRAINT movie_video_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY movies
                ADD CONSTRAINT movies_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY notification_preferences
                ADD CONSTRAINT notification_preferences_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY notifications
                ADD CONSTRAINT notifications_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY oauth_access_tokens
                ADD CONSTRAINT oauth_access_tokens_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY oauth_auth_codes
                ADD CONSTRAINT oauth_auth_codes_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY oauth_clients
                ADD CONSTRAINT oauth_clients_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY oauth_device_codes
                ADD CONSTRAINT oauth_device_codes_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY oauth_refresh_tokens
                ADD CONSTRAINT oauth_refresh_tokens_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY oauth_scopes
                ADD CONSTRAINT oauth_scopes_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY oauth_token_metadata
                ADD CONSTRAINT oauth_token_metadata_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY pairing_sessions
                ADD CONSTRAINT pairing_sessions_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY party_events
                ADD CONSTRAINT party_events_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY party_members
                ADD CONSTRAINT party_members_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY party_sessions
                ADD CONSTRAINT party_sessions_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY passkeys
                ADD CONSTRAINT passkeys_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY password_reset_tokens
                ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (user_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY player_preferences
                ADD CONSTRAINT player_preferences_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY playlist_collaborators
                ADD CONSTRAINT playlist_collaborators_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY playlist_song
                ADD CONSTRAINT playlist_song_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY playlist_statistics
                ADD CONSTRAINT playlist_statistics_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY playlists
                ADD CONSTRAINT playlists_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY preference_history
                ADD CONSTRAINT preference_history_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY push_subscriptions
                ADD CONSTRAINT push_subscriptions_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY radio_sessions
                ADD CONSTRAINT radio_sessions_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY radio_sources
                ADD CONSTRAINT radio_sources_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY radio_stations
                ADD CONSTRAINT radio_stations_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY recommendation_jobs
                ADD CONSTRAINT recommendation_jobs_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY recommendations
                ADD CONSTRAINT recommendations_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY scheduled_jobs
                ADD CONSTRAINT scheduled_jobs_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY scheduler_occurrence_executions
                ADD CONSTRAINT scheduler_occurrence_executions_pkey PRIMARY KEY (occurrence_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY scheduler_occurrences
                ADD CONSTRAINT scheduler_occurrences_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY server_instances
                ADD CONSTRAINT server_instances_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY songs
                ADD CONSTRAINT songs_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY starred_stations
                ADD CONSTRAINT starred_stations_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY system_settings
                ADD CONSTRAINT system_settings_pkey PRIMARY KEY (key)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY third_party_credentials
                ADD CONSTRAINT third_party_credentials_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY transcode_jobs
                ADD CONSTRAINT transcode_jobs_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY transcode_sessions
                ADD CONSTRAINT transcode_sessions_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY country_subscriptions
                ADD CONSTRAINT uniq_country_subscriptions_public_id UNIQUE (public_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY devices
                ADD CONSTRAINT uniq_devices_public_id UNIQUE (public_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY domain_event_outbox_delivery
                ADD CONSTRAINT uniq_domain_event_outbox_delivery_channel_notification_id UNIQUE (channel, notification_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY eq_device_profiles
                ADD CONSTRAINT uniq_eq_device_profiles_public_id UNIQUE (public_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY genres
                ADD CONSTRAINT uniq_genres_public_id UNIQUE (public_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY job_monitors
                ADD CONSTRAINT uniq_job_monitors_job_id UNIQUE (job_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY libraries
                ADD CONSTRAINT uniq_libraries_public_id UNIQUE (public_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY listening_sessions
                ADD CONSTRAINT uniq_listening_sessions_public_id UNIQUE (public_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY login_blocks
                ADD CONSTRAINT uniq_login_blocks_public_id UNIQUE (public_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY passkeys
                ADD CONSTRAINT uniq_passkeys_public_id UNIQUE (public_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY radio_sources
                ADD CONSTRAINT uniq_radio_sources_public_id UNIQUE (public_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY radio_stations
                ADD CONSTRAINT uniq_radio_stations_public_id UNIQUE (public_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY recommendations
                ADD CONSTRAINT uniq_recommendations_public_id UNIQUE (public_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY scheduled_jobs
                ADD CONSTRAINT uniq_scheduled_jobs_public_id UNIQUE (public_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY scheduler_occurrence_executions
                ADD CONSTRAINT uniq_scheduler_occurrence_executions_attempt_id UNIQUE (attempt_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY scheduler_occurrences
                ADD CONSTRAINT uniq_scheduler_occurrences_id_job_id UNIQUE (id, job_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY scheduler_occurrences
                ADD CONSTRAINT uniq_scheduler_occurrences_public_id UNIQUE (public_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY webhooks
                ADD CONSTRAINT uniq_webhooks_public_id UNIQUE (public_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY worker_deployment_containers
                ADD CONSTRAINT uniq_worker_deployment_containers_daemon_id_container_id UNIQUE (daemon_id, container_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY worker_deployment_retirements
                ADD CONSTRAINT uniq_worker_deployment_retirements_daemon_id_container_id UNIQUE (daemon_id, container_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY user_accent_colors
                ADD CONSTRAINT user_accent_colors_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY user_favorites
                ADD CONSTRAINT user_favorites_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY user_library_access
                ADD CONSTRAINT user_library_access_pkey PRIMARY KEY (user_id, library_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY user_settings
                ADD CONSTRAINT user_settings_pkey PRIMARY KEY (user_id, key)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY user_sidebar_configs
                ADD CONSTRAINT user_sidebar_configs_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY user_theme_moods
                ADD CONSTRAINT user_theme_moods_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY users
                ADD CONSTRAINT users_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY videos
                ADD CONSTRAINT videos_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY webhook_delivery_logs
                ADD CONSTRAINT webhook_delivery_logs_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY webhooks
                ADD CONSTRAINT webhooks_pkey PRIMARY KEY (id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY worker_deployment_containers
                ADD CONSTRAINT worker_deployment_containers_pkey PRIMARY KEY (namespace, boot_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY worker_deployment_creations
                ADD CONSTRAINT worker_deployment_creations_pkey PRIMARY KEY (namespace, boot_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY worker_deployment_leases
                ADD CONSTRAINT worker_deployment_leases_pkey PRIMARY KEY (namespace)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY worker_deployment_retirements
                ADD CONSTRAINT worker_deployment_retirements_pkey PRIMARY KEY (namespace, boot_id)
            SQL);
        $this->addSql('CREATE INDEX idx_albums_cover_image_id ON albums USING btree (cover_image_id)');
        $this->addSql('CREATE INDEX idx_albums_cover_image_null ON albums USING btree (id) WHERE (cover_image_id IS NULL)');
        $this->addSql('CREATE INDEX idx_albums_library_id ON albums USING btree (library_id)');
        $this->addSql('CREATE INDEX idx_albums_public_id_trgm ON albums USING gin (public_id gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_albums_title ON albums USING btree (title)');
        $this->addSql('CREATE INDEX idx_albums_title_pgroonga ON albums USING pgroonga (title) WITH (plugins=\'token_filters/stem\', tokenizer=\'TokenNgram\', normalizer=\'NormalizerAuto\', token_filters=\'TokenFilterStem\')');
        $this->addSql('CREATE INDEX idx_artist_album_album_id ON artist_album USING btree (album_id)');
        $this->addSql('CREATE INDEX idx_artist_song_song_id ON artist_song USING btree (song_id)');
        $this->addSql('CREATE INDEX idx_artists_cover_image_id ON artists USING btree (cover_image_id)');
        $this->addSql('CREATE INDEX idx_artists_name ON artists USING btree (name)');
        $this->addSql('CREATE INDEX idx_artists_name_pgroonga ON artists USING pgroonga (name) WITH (plugins=\'token_filters/stem\', tokenizer=\'TokenNgram\', normalizer=\'NormalizerAuto\', token_filters=\'TokenFilterStem\')');
        $this->addSql('CREATE INDEX idx_artists_public_id_trgm ON artists USING gin (public_id gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_country_subscriptions_source_id ON country_subscriptions USING btree (source_id)');
        $this->addSql('CREATE INDEX idx_domain_event_outbox_claim_expiry ON domain_event_outbox USING btree (lease_until) WHERE ((relayed_at IS NULL) AND (dead_lettered_at IS NULL))');
        $this->addSql('CREATE INDEX idx_domain_event_outbox_delivery_pending ON domain_event_outbox_delivery USING btree (next_attempt_at, created_at, id, lease_until) WHERE ((relayed_at IS NULL) AND (dead_lettered_at IS NULL))');
        $this->addSql('CREATE INDEX idx_domain_event_outbox_pending ON domain_event_outbox USING btree (created_at) WHERE (relayed_at IS NULL)');
        $this->addSql('CREATE INDEX idx_domain_event_outbox_retry ON domain_event_outbox USING btree (next_attempt_at) WHERE ((relayed_at IS NULL) AND (dead_lettered_at IS NULL))');
        $this->addSql('CREATE INDEX idx_failed_messages_queue_name_available_at_delivered_at_id ON failed_messages USING btree (queue_name, available_at, delivered_at, id)');
        $this->addSql('CREATE INDEX idx_genre_album_album_id ON genre_album USING btree (album_id)');
        $this->addSql('CREATE INDEX idx_genre_movie_movie_id ON genre_movie USING btree (movie_id)');
        $this->addSql('CREATE INDEX idx_genre_song_song_id ON genre_song USING btree (song_id)');
        $this->addSql('CREATE INDEX idx_genres_name_pgroonga ON genres USING pgroonga (name) WITH (plugins=\'token_filters/stem\', tokenizer=\'TokenNgram\', normalizer=\'NormalizerAuto\', token_filters=\'TokenFilterStem\')');
        $this->addSql('CREATE INDEX idx_genres_parent_id ON genres USING btree (parent_id)');
        $this->addSql('CREATE INDEX idx_images_album_id ON images USING btree (album_id)');
        $this->addSql('CREATE INDEX idx_images_artist_id ON images USING btree (artist_id)');
        $this->addSql('CREATE INDEX idx_images_imageable ON images USING btree (imageable_type, album_id, artist_id, playlist_id)');
        $this->addSql('CREATE INDEX idx_images_playlist_id ON images USING btree (playlist_id)');
        $this->addSql('CREATE INDEX idx_images_public_id_trgm ON images USING gin (public_id gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_job_monitors_name_created_at ON job_monitors USING btree (name, created_at)');
        $this->addSql('CREATE INDEX idx_job_monitors_queue_created_at ON job_monitors USING btree (queue, created_at)');
        $this->addSql('CREATE INDEX idx_job_monitors_status_created_at ON job_monitors USING btree (status, created_at)');
        $this->addSql('CREATE INDEX idx_login_blocks_email ON login_blocks USING btree (email)');
        $this->addSql('CREATE INDEX idx_login_blocks_ip_address_created_at ON login_blocks USING btree (ip_address, created_at)');
        $this->addSql('CREATE INDEX idx_media_activities_activity_type_user_id ON media_activities USING btree (activity_type, user_id)');
        $this->addSql('CREATE INDEX idx_media_activities_album_id ON media_activities USING btree (album_id)');
        $this->addSql('CREATE INDEX idx_media_activities_artist_id ON media_activities USING btree (artist_id)');
        $this->addSql('CREATE INDEX idx_media_activities_movie_id ON media_activities USING btree (movie_id)');
        $this->addSql('CREATE INDEX idx_media_activities_public_id_trgm ON media_activities USING gin (public_id gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_media_activities_song_id ON media_activities USING btree (song_id)');
        $this->addSql('CREATE INDEX idx_media_activities_user_id ON media_activities USING btree (user_id)');
        $this->addSql('CREATE INDEX idx_movie_video_movie_id ON movie_video USING btree (movie_id)');
        $this->addSql('CREATE INDEX idx_movie_video_video_id ON movie_video USING btree (video_id)');
        $this->addSql('CREATE INDEX idx_movies_library_id ON movies USING btree (library_id)');
        $this->addSql('CREATE INDEX idx_movies_public_id_trgm ON movies USING gin (public_id gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_movies_title_pgroonga ON movies USING pgroonga (title) WITH (plugins=\'token_filters/stem\', tokenizer=\'TokenNgram\', normalizer=\'NormalizerAuto\', token_filters=\'TokenFilterStem\')');
        $this->addSql('CREATE INDEX idx_notifications_user_id_created_at ON notifications USING btree (user_id, created_at DESC)');
        $this->addSql('CREATE INDEX idx_notifications_user_id_is_read ON notifications USING btree (user_id, is_read)');
        $this->addSql('CREATE INDEX idx_oauth_access_tokens_chain_id ON oauth_access_tokens USING btree (chain_id)');
        $this->addSql('CREATE INDEX idx_oauth_access_tokens_client_id ON oauth_access_tokens USING btree (client_id)');
        $this->addSql('CREATE INDEX idx_oauth_access_tokens_user_id ON oauth_access_tokens USING btree (user_id)');
        $this->addSql('CREATE INDEX idx_oauth_auth_codes_client_id ON oauth_auth_codes USING btree (client_id)');
        $this->addSql('CREATE INDEX idx_oauth_auth_codes_user_id ON oauth_auth_codes USING btree (user_id)');
        $this->addSql('CREATE INDEX idx_oauth_clients_public_id_trgm ON oauth_clients USING gin (public_id gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_oauth_clients_user_id ON oauth_clients USING btree (user_id)');
        $this->addSql('CREATE INDEX idx_oauth_device_codes_client_id ON oauth_device_codes USING btree (client_id)');
        $this->addSql('CREATE INDEX idx_oauth_device_codes_user_id ON oauth_device_codes USING btree (user_id)');
        $this->addSql('CREATE INDEX idx_oauth_refresh_tokens_access_token_id ON oauth_refresh_tokens USING btree (access_token_id)');
        $this->addSql('CREATE INDEX idx_oauth_refresh_tokens_chain_id ON oauth_refresh_tokens USING btree (chain_id)');
        $this->addSql('CREATE INDEX idx_oauth_refresh_tokens_previous_refresh_token_id ON oauth_refresh_tokens USING btree (previous_refresh_token_id)');
        $this->addSql('CREATE INDEX idx_pairing_sessions_server_id ON pairing_sessions USING btree (server_id)');
        $this->addSql('CREATE INDEX idx_party_events_occurred_at ON party_events USING btree (occurred_at)');
        $this->addSql('CREATE INDEX idx_party_events_session_id ON party_events USING btree (session_id)');
        $this->addSql('CREATE INDEX idx_party_events_user_id ON party_events USING btree (user_id)');
        $this->addSql('CREATE INDEX idx_party_members_session_id ON party_members USING btree (session_id)');
        $this->addSql('CREATE INDEX idx_party_sessions_host_user_id ON party_sessions USING btree (host_user_id)');
        $this->addSql('CREATE INDEX idx_party_sessions_is_active ON party_sessions USING btree (is_active)');
        $this->addSql('CREATE INDEX idx_party_sessions_transcode_job_id ON party_sessions USING btree (transcode_job_id)');
        $this->addSql('CREATE INDEX idx_party_sessions_video_id ON party_sessions USING btree (video_id)');
        $this->addSql('CREATE INDEX idx_passkeys_user_id ON passkeys USING btree (user_id)');
        $this->addSql('CREATE INDEX idx_playlist_collaborators_user_id ON playlist_collaborators USING btree (user_id)');
        $this->addSql('CREATE INDEX idx_playlist_song_song_id ON playlist_song USING btree (song_id)');
        $this->addSql('CREATE INDEX idx_playlist_statistics_playlist_id ON playlist_statistics USING btree (playlist_id)');
        $this->addSql('CREATE INDEX idx_playlists_public_id_trgm ON playlists USING gin (public_id gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_playlists_user_id ON playlists USING btree (user_id)');
        $this->addSql('CREATE INDEX idx_preference_history_user_id_preference_type_version ON preference_history USING btree (user_id, preference_type, version)');
        $this->addSql('CREATE INDEX idx_push_subscriptions_user_id ON push_subscriptions USING btree (user_id)');
        $this->addSql('CREATE INDEX idx_radio_sessions_active_station_id ON radio_sessions USING btree (active_station_id)');
        $this->addSql('CREATE INDEX idx_radio_stations_country ON radio_stations USING btree (country)');
        $this->addSql('CREATE INDEX idx_radio_stations_source_id_country ON radio_stations USING btree (source_id, country)');
        $this->addSql('CREATE INDEX idx_recommendation_jobs_original_job_id ON recommendation_jobs USING btree (original_job_id)');
        $this->addSql('CREATE INDEX idx_recommendation_jobs_status ON recommendation_jobs USING btree (status)');
        $this->addSql('CREATE INDEX idx_recommendation_jobs_user_id ON recommendation_jobs USING btree (user_id)');
        $this->addSql('CREATE INDEX idx_recommendations_target_type_target_id ON recommendations USING btree (target_type, target_id)');
        $this->addSql('CREATE INDEX idx_recommendations_user_id ON recommendations USING btree (user_id)');
        $this->addSql('CREATE INDEX idx_scheduled_jobs_next_run_at ON scheduled_jobs USING btree (next_run_at)');
        $this->addSql('CREATE INDEX idx_scheduled_jobs_recovery_after_id ON scheduled_jobs USING btree (recovery_after, id) WHERE (status = \'active\'::text)');
        $this->addSql('CREATE INDEX idx_scheduled_jobs_status ON scheduled_jobs USING btree (status)');
        $this->addSql('CREATE INDEX idx_scheduler_occurrences_pending_dispatch ON scheduler_occurrences USING btree (dispatch_after, scheduled_for, id) WHERE (dispatched_at IS NULL)');
        $this->addSql('CREATE INDEX idx_songs_album_id ON songs USING btree (album_id)');
        $this->addSql('CREATE INDEX idx_songs_hash ON songs USING btree (hash)');
        $this->addSql('CREATE INDEX idx_songs_public_id_trgm ON songs USING gin (public_id gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_songs_title_id ON songs USING btree (title, id)');
        $this->addSql('CREATE INDEX idx_songs_title_pgroonga ON songs USING pgroonga (title) WITH (plugins=\'token_filters/stem\', tokenizer=\'TokenNgram\', normalizer=\'NormalizerAuto\', token_filters=\'TokenFilterStem\')');
        $this->addSql('CREATE INDEX idx_starred_stations_station_id ON starred_stations USING btree (station_id)');
        $this->addSql('CREATE INDEX idx_third_party_credentials_user_id ON third_party_credentials USING btree (user_id)');
        $this->addSql('CREATE INDEX idx_transcode_jobs_status ON transcode_jobs USING btree (status)');
        $this->addSql('CREATE INDEX idx_transcode_sessions_job_id ON transcode_sessions USING btree (job_id)');
        $this->addSql('CREATE INDEX idx_transcode_sessions_state ON transcode_sessions USING btree (state)');
        $this->addSql('CREATE INDEX idx_transcode_sessions_user_id ON transcode_sessions USING btree (user_id)');
        $this->addSql('CREATE INDEX idx_transcode_sessions_video_id ON transcode_sessions USING btree (video_id)');
        $this->addSql('CREATE INDEX idx_user_library_access_library_id ON user_library_access USING btree (library_id)');
        $this->addSql('CREATE INDEX idx_users_public_id_trgm ON users USING gin (public_id gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_webhook_delivery_logs_webhook_id ON webhook_delivery_logs USING btree (webhook_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_albums_public_id ON albums USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_artist_album_artist_id_album_id_role ON artist_album USING btree (artist_id, album_id, role)');
        $this->addSql('CREATE UNIQUE INDEX uniq_artist_song_artist_id_song_id_role ON artist_song USING btree (artist_id, song_id, role)');
        $this->addSql('CREATE UNIQUE INDEX uniq_artists_public_id ON artists USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_audio_preferences_user_id ON audio_preferences USING btree (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_country_subscriptions_user_id_source_id_country_code ON country_subscriptions USING btree (user_id, source_id, country_code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_devices_user_id_device_id ON devices USING btree (user_id, device_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_email_verification_tokens_token_hash ON email_verification_tokens USING btree (token_hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_eq_device_profiles_user_id_name ON eq_device_profiles USING btree (user_id, name)');
        $this->addSql('CREATE UNIQUE INDEX uniq_genre_album_genre_id_album_id ON genre_album USING btree (genre_id, album_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_genre_movie_genre_id_movie_id ON genre_movie USING btree (genre_id, movie_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_genre_song_genre_id_song_id ON genre_song USING btree (genre_id, song_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_genres_name_lower ON genres USING btree (lower(name))');
        $this->addSql('CREATE UNIQUE INDEX uniq_genres_slug ON genres USING btree (slug)');
        $this->addSql('CREATE UNIQUE INDEX uniq_images_public_id ON images USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_layout_preferences_user_id ON layout_preferences USING btree (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_libraries_claim_id ON libraries USING btree (claim_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_libraries_slug ON libraries USING btree (slug)');
        $this->addSql('CREATE UNIQUE INDEX uniq_library_file_index_library_id_path ON library_file_index USING btree (library_id, path)');
        $this->addSql('CREATE UNIQUE INDEX uniq_listening_sessions_user_id ON listening_sessions USING btree (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_lyrics_song_id ON lyrics USING btree (song_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_media_activities_public_id ON media_activities USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_movie_collections_tmdb_collection_id ON movie_collections USING btree (tmdb_collection_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_movies_public_id ON movies USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_preferences_user_id_category_channel ON notification_preferences USING btree (user_id, category, channel)');
        $this->addSql('CREATE UNIQUE INDEX uniq_notifications_public_id ON notifications USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_oauth_access_tokens_token_id ON oauth_access_tokens USING btree (token_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_oauth_auth_codes_code_id ON oauth_auth_codes USING btree (code_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_oauth_clients_public_id ON oauth_clients USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_oauth_device_codes_device_code ON oauth_device_codes USING btree (device_code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_oauth_device_codes_user_code ON oauth_device_codes USING btree (user_code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_oauth_refresh_tokens_token_id ON oauth_refresh_tokens USING btree (token_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_oauth_token_metadata_token_id ON oauth_token_metadata USING btree (token_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_pairing_sessions_pairing_code ON pairing_sessions USING btree (pairing_code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_pairing_sessions_public_id ON pairing_sessions USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_party_members_public_id ON party_members USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_party_members_user_id_session_id ON party_members USING btree (user_id, session_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_party_sessions_public_id ON party_sessions USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_passkeys_credential_id ON passkeys USING btree (credential_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_password_reset_tokens_token_hash ON password_reset_tokens USING btree (token_hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_player_preferences_user_id ON player_preferences USING btree (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_playlist_collaborators_playlist_id_user_id ON playlist_collaborators USING btree (playlist_id, user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_playlist_song_playlist_id_song_id ON playlist_song USING btree (playlist_id, song_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_playlists_public_id ON playlists USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_push_subscriptions_endpoint ON push_subscriptions USING btree (endpoint)');
        $this->addSql('CREATE UNIQUE INDEX uniq_radio_sessions_user_id ON radio_sessions USING btree (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_radio_stations_source_id_external_id ON radio_stations USING btree (source_id, external_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_recommendation_jobs_public_id ON recommendation_jobs USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_recommendations_source_target_name_user_id ON recommendations USING btree (source_type, source_id, target_type, target_id, name, user_id) NULLS NOT DISTINCT');
        $this->addSql('CREATE UNIQUE INDEX uniq_scheduler_occurrence_executions_unresolved_job ON scheduler_occurrence_executions USING btree (job_id) WHERE (returned_at IS NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_scheduler_occurrences_scheduled_job_minute ON scheduler_occurrences USING btree (job_id, scheduled_for) WHERE (origin = \'scheduled\'::text)');
        $this->addSql('CREATE UNIQUE INDEX uniq_server_instances_public_id ON server_instances USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_server_instances_server_url ON server_instances USING btree (server_url)');
        $this->addSql('CREATE UNIQUE INDEX uniq_songs_public_id ON songs USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_starred_stations_user_id_station_id ON starred_stations USING btree (user_id, station_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_third_party_credentials_public_id ON third_party_credentials USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_transcode_jobs_public_id ON transcode_jobs USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_transcode_jobs_video_id_quality_tier_name ON transcode_jobs USING btree (video_id, quality_tier_name)');
        $this->addSql('CREATE UNIQUE INDEX uniq_transcode_sessions_public_id ON transcode_sessions USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_accent_colors_user_id ON user_accent_colors USING btree (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_favorites_public_id ON user_favorites USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_favorites_user_id_entity_type_entity_public_id ON user_favorites USING btree (user_id, entity_type, entity_public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_sidebar_configs_user_id_media_type ON user_sidebar_configs USING btree (user_id, media_type)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_theme_moods_user_id ON user_theme_moods USING btree (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_users_email ON users USING btree (email)');
        $this->addSql('CREATE UNIQUE INDEX uniq_users_public_id ON users USING btree (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_videos_hash ON videos USING btree (hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_videos_public_id ON videos USING btree (public_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY albums
                ADD CONSTRAINT fk_albums_cover_image_id FOREIGN KEY (cover_image_id) REFERENCES images(id) ON DELETE SET NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY albums
                ADD CONSTRAINT fk_albums_library_id FOREIGN KEY (library_id) REFERENCES libraries(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY artist_album
                ADD CONSTRAINT fk_artist_album_album_id FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY artist_album
                ADD CONSTRAINT fk_artist_album_artist_id FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY artist_song
                ADD CONSTRAINT fk_artist_song_artist_id FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY artist_song
                ADD CONSTRAINT fk_artist_song_song_id FOREIGN KEY (song_id) REFERENCES songs(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY artists
                ADD CONSTRAINT fk_artists_cover_image_id FOREIGN KEY (cover_image_id) REFERENCES images(id) ON DELETE SET NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY audio_preferences
                ADD CONSTRAINT fk_audio_preferences_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY country_subscriptions
                ADD CONSTRAINT fk_country_subscriptions_source_id FOREIGN KEY (source_id) REFERENCES radio_sources(id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY country_subscriptions
                ADD CONSTRAINT fk_country_subscriptions_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY devices
                ADD CONSTRAINT fk_devices_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY domain_event_outbox_receipt
                ADD CONSTRAINT fk_domain_event_outbox_receipt_outbox_id FOREIGN KEY (outbox_id) REFERENCES domain_event_outbox(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY email_verification_tokens
                ADD CONSTRAINT fk_email_verification_tokens_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY eq_device_profiles
                ADD CONSTRAINT fk_eq_device_profiles_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY genre_album
                ADD CONSTRAINT fk_genre_album_album_id FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY genre_album
                ADD CONSTRAINT fk_genre_album_genre_id FOREIGN KEY (genre_id) REFERENCES genres(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY genre_movie
                ADD CONSTRAINT fk_genre_movie_genre_id FOREIGN KEY (genre_id) REFERENCES genres(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY genre_movie
                ADD CONSTRAINT fk_genre_movie_movie_id FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY genre_song
                ADD CONSTRAINT fk_genre_song_genre_id FOREIGN KEY (genre_id) REFERENCES genres(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY genre_song
                ADD CONSTRAINT fk_genre_song_song_id FOREIGN KEY (song_id) REFERENCES songs(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY genres
                ADD CONSTRAINT fk_genres_parent_id FOREIGN KEY (parent_id) REFERENCES genres(id) ON DELETE SET NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY images
                ADD CONSTRAINT fk_images_album_id FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE SET NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY images
                ADD CONSTRAINT fk_images_artist_id FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE SET NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY images
                ADD CONSTRAINT fk_images_playlist_id FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE SET NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY layout_preferences
                ADD CONSTRAINT fk_layout_preferences_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY library_file_index
                ADD CONSTRAINT fk_library_file_index_library_id FOREIGN KEY (library_id) REFERENCES libraries(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY listening_sessions
                ADD CONSTRAINT fk_listening_sessions_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY lyrics
                ADD CONSTRAINT fk_lyrics_song_id FOREIGN KEY (song_id) REFERENCES songs(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY media_activities
                ADD CONSTRAINT fk_media_activities_album_id FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE SET NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY media_activities
                ADD CONSTRAINT fk_media_activities_artist_id FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE SET NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY media_activities
                ADD CONSTRAINT fk_media_activities_movie_id FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE SET NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY media_activities
                ADD CONSTRAINT fk_media_activities_song_id FOREIGN KEY (song_id) REFERENCES songs(id) ON DELETE SET NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY media_activities
                ADD CONSTRAINT fk_media_activities_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY movie_video
                ADD CONSTRAINT fk_movie_video_movie_id FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY movie_video
                ADD CONSTRAINT fk_movie_video_video_id FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY movies
                ADD CONSTRAINT fk_movies_library_id FOREIGN KEY (library_id) REFERENCES libraries(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY notification_preferences
                ADD CONSTRAINT fk_notification_preferences_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY notifications
                ADD CONSTRAINT fk_notifications_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY oauth_access_tokens
                ADD CONSTRAINT fk_oauth_access_tokens_client_id FOREIGN KEY (client_id) REFERENCES oauth_clients(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY oauth_access_tokens
                ADD CONSTRAINT fk_oauth_access_tokens_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY oauth_auth_codes
                ADD CONSTRAINT fk_oauth_auth_codes_client_id FOREIGN KEY (client_id) REFERENCES oauth_clients(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY oauth_auth_codes
                ADD CONSTRAINT fk_oauth_auth_codes_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY oauth_clients
                ADD CONSTRAINT fk_oauth_clients_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY oauth_device_codes
                ADD CONSTRAINT fk_oauth_device_codes_client_id FOREIGN KEY (client_id) REFERENCES oauth_clients(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY oauth_device_codes
                ADD CONSTRAINT fk_oauth_device_codes_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY oauth_refresh_tokens
                ADD CONSTRAINT fk_oauth_refresh_tokens_access_token_id FOREIGN KEY (access_token_id) REFERENCES oauth_access_tokens(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY oauth_refresh_tokens
                ADD CONSTRAINT fk_oauth_refresh_tokens_previous_refresh_token_id FOREIGN KEY (previous_refresh_token_id) REFERENCES oauth_refresh_tokens(id) ON DELETE SET NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY oauth_token_metadata
                ADD CONSTRAINT fk_oauth_token_metadata_token_id FOREIGN KEY (token_id) REFERENCES oauth_access_tokens(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY pairing_sessions
                ADD CONSTRAINT fk_pairing_sessions_server_id FOREIGN KEY (server_id) REFERENCES server_instances(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY party_events
                ADD CONSTRAINT fk_party_events_session_id FOREIGN KEY (session_id) REFERENCES party_sessions(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY party_events
                ADD CONSTRAINT fk_party_events_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY party_members
                ADD CONSTRAINT fk_party_members_session_id FOREIGN KEY (session_id) REFERENCES party_sessions(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY party_members
                ADD CONSTRAINT fk_party_members_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY party_sessions
                ADD CONSTRAINT fk_party_sessions_host_user_id FOREIGN KEY (host_user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY party_sessions
                ADD CONSTRAINT fk_party_sessions_transcode_job_id FOREIGN KEY (transcode_job_id) REFERENCES transcode_jobs(id) ON DELETE SET NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY party_sessions
                ADD CONSTRAINT fk_party_sessions_video_id FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY passkeys
                ADD CONSTRAINT fk_passkeys_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY password_reset_tokens
                ADD CONSTRAINT fk_password_reset_tokens_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY player_preferences
                ADD CONSTRAINT fk_player_preferences_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY playlist_collaborators
                ADD CONSTRAINT fk_playlist_collaborators_playlist_id FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY playlist_collaborators
                ADD CONSTRAINT fk_playlist_collaborators_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY playlist_song
                ADD CONSTRAINT fk_playlist_song_playlist_id FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY playlist_song
                ADD CONSTRAINT fk_playlist_song_song_id FOREIGN KEY (song_id) REFERENCES songs(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY playlist_statistics
                ADD CONSTRAINT fk_playlist_statistics_playlist_id FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY playlists
                ADD CONSTRAINT fk_playlists_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY preference_history
                ADD CONSTRAINT fk_preference_history_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY push_subscriptions
                ADD CONSTRAINT fk_push_subscriptions_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY radio_sessions
                ADD CONSTRAINT fk_radio_sessions_active_station_id FOREIGN KEY (active_station_id) REFERENCES radio_stations(id) ON DELETE SET NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY radio_sessions
                ADD CONSTRAINT fk_radio_sessions_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY radio_stations
                ADD CONSTRAINT fk_radio_stations_source_id FOREIGN KEY (source_id) REFERENCES radio_sources(id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY recommendation_jobs
                ADD CONSTRAINT fk_recommendation_jobs_original_job_id FOREIGN KEY (original_job_id) REFERENCES recommendation_jobs(id) ON DELETE SET NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY recommendation_jobs
                ADD CONSTRAINT fk_recommendation_jobs_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY recommendations
                ADD CONSTRAINT fk_recommendations_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY scheduler_occurrence_executions
                ADD CONSTRAINT fk_scheduler_occurrence_executions_occurrence_id_job_id FOREIGN KEY (occurrence_id, job_id) REFERENCES scheduler_occurrences(id, job_id) ON DELETE RESTRICT
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY songs
                ADD CONSTRAINT fk_songs_album_id FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY starred_stations
                ADD CONSTRAINT fk_starred_stations_station_id FOREIGN KEY (station_id) REFERENCES radio_stations(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY starred_stations
                ADD CONSTRAINT fk_starred_stations_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY third_party_credentials
                ADD CONSTRAINT fk_third_party_credentials_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY transcode_jobs
                ADD CONSTRAINT fk_transcode_jobs_video_id FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY transcode_sessions
                ADD CONSTRAINT fk_transcode_sessions_job_id FOREIGN KEY (job_id) REFERENCES transcode_jobs(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY transcode_sessions
                ADD CONSTRAINT fk_transcode_sessions_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY transcode_sessions
                ADD CONSTRAINT fk_transcode_sessions_video_id FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY user_accent_colors
                ADD CONSTRAINT fk_user_accent_colors_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY user_favorites
                ADD CONSTRAINT fk_user_favorites_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY user_library_access
                ADD CONSTRAINT fk_user_library_access_library_id FOREIGN KEY (library_id) REFERENCES libraries(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY user_library_access
                ADD CONSTRAINT fk_user_library_access_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY user_settings
                ADD CONSTRAINT fk_user_settings_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY user_sidebar_configs
                ADD CONSTRAINT fk_user_sidebar_configs_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY user_theme_moods
                ADD CONSTRAINT fk_user_theme_moods_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ONLY webhook_delivery_logs
                ADD CONSTRAINT fk_webhook_delivery_logs_webhook_id FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
            SQL);

        // Both schedules begin at their next UTC occurrence, from one shared clock sample.
        $this->addSql(
            <<<'SQL'
                WITH sampled_clock AS MATERIALIZED (SELECT clock_timestamp() AS now),
                schedules (id, name, expression, command, description, parameters, daily_time) AS (
                    VALUES
                        (CAST(:purge_id AS uuid), 'Purge expired OAuth codes', :purge_expression, :purge_command,
                            'Deletes authorization codes and device codes that expired more than an hour ago.',
                            '[]', INTERVAL '3 hours 30 minutes'),
                        (CAST(:generation_id AS uuid), 'Generate recommendations', :generation_expression, :generation_command,
                            'Regenerates recommendation snapshots while the recommendations.auto_generate setting is on.',
                            :generation_parameters, INTERVAL '4 hours')
                )
                INSERT INTO scheduled_jobs (
                    id, public_id, revision, name, expression, job_type, command, status,
                    description, parameters, created_at, updated_at, next_run_at, recovery_after, run_count
                )
                SELECT id, translate(substr(encode(gen_random_bytes(16), 'base64'), 1, 21), '+/', '-_'),
                    gen_random_uuid(), name, expression, 'messenger', command, 'active', description,
                    CAST(parameters AS json), now, now,
                    (date_trunc('day', now AT TIME ZONE 'UTC') + daily_time
                        + CASE WHEN date_trunc('day', now AT TIME ZONE 'UTC') + daily_time <= now AT TIME ZONE 'UTC'
                            THEN INTERVAL '1 day' ELSE INTERVAL '0' END) AT TIME ZONE 'UTC',
                    now, 0
                FROM schedules CROSS JOIN sampled_clock
                SQL,
            [
                'purge_id' => self::PURGE_JOB_ID,
                'purge_expression' => self::PURGE_EXPRESSION,
                'purge_command' => self::PURGE_COMMAND,
                'generation_id' => self::GENERATION_JOB_ID,
                'generation_expression' => self::GENERATION_EXPRESSION,
                'generation_command' => self::GENERATION_COMMAND,
                'generation_parameters' => self::GENERATION_PARAMETERS,
            ],
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The initial application schema cannot be downgraded. Resetting a database requires explicit approval for that target and a separate manual reset.');
    }
}
