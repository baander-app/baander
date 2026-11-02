<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve listening sessions and add typed playback destinations, endpoint incarnations and acknowledged command delivery.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql(<<<'SQL'
            ALTER TABLE listening_sessions
            ADD revision integer DEFAULT 0 NOT NULL CHECK (revision >= 0),
            ADD media jsonb DEFAULT NULL,
            ADD desired_state text DEFAULT 'stopped' NOT NULL CHECK (desired_state IN ('playing','paused','stopped')),
            ADD observed_state text DEFAULT 'unknown' NOT NULL CHECK (observed_state IN ('playing','paused','stopped','unknown')),
            ADD selected_destination_id uuid DEFAULT NULL,
            ADD active_incarnation_id uuid DEFAULT NULL
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE listening_sessions SET desired_state=playback_state,
            media=CASE WHEN jsonb_typeof(queue)='array' AND jsonb_array_length(queue)>current_track_index
                THEN jsonb_build_object('kind','song','publicId',queue->>current_track_index) ELSE NULL END
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE listening_sessions ADD CONSTRAINT uniq_playback_session_owner UNIQUE (id,user_id)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE playback_destinations (
                id uuid PRIMARY KEY, public_id text NOT NULL UNIQUE,
                user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                device_id uuid NOT NULL, endpoint_id uuid,
                name text NOT NULL, kind text NOT NULL DEFAULT 'browser',
                capabilities jsonb NOT NULL DEFAULT '["song","video"]',
                created_at timestamptz NOT NULL,
                CONSTRAINT uniq_playback_destination_owner UNIQUE(id,user_id),
                CONSTRAINT uniq_playback_destination_tab UNIQUE(user_id,device_id,endpoint_id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE playback_destination_incarnations (
                id uuid PRIMARY KEY, destination_id uuid NOT NULL, user_id uuid NOT NULL,
                epoch integer NOT NULL CHECK(epoch>0), capability_hash text NOT NULL,
                expires_at timestamptz NOT NULL, retired_at timestamptz,
                observed_volume double precision CHECK(observed_volume>=0 AND observed_volume<=1),
                observed_state text NOT NULL DEFAULT 'unknown', position double precision NOT NULL DEFAULT 0 CHECK(position>=0),
                CONSTRAINT fk_playback_incarnation_destination FOREIGN KEY(destination_id,user_id)
                    REFERENCES playback_destinations(id,user_id) ON DELETE CASCADE,
                CONSTRAINT uniq_playback_incarnation_owner UNIQUE(id,user_id),
                CONSTRAINT uniq_playback_incarnation_epoch UNIQUE(destination_id,epoch)
            )
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE listening_sessions ADD CONSTRAINT fk_playback_selected_destination FOREIGN KEY(selected_destination_id,user_id)
            REFERENCES playback_destinations(id,user_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE listening_sessions ADD CONSTRAINT fk_playback_active_incarnation FOREIGN KEY(active_incarnation_id,user_id)
            REFERENCES playback_destination_incarnations(id,user_id)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE playback_transfers (
                id uuid PRIMARY KEY, session_id uuid NOT NULL, user_id uuid NOT NULL, request_id uuid NOT NULL, request_digest text NOT NULL,
                source_incarnation_id uuid, target_incarnation_id uuid NOT NULL,
                state text NOT NULL CHECK(state IN ('preparing','quiescing','starting','committed','uncertain','failed')),
                desired_state text NOT NULL CHECK(desired_state IN ('playing','paused','stopped')),
                position double precision NOT NULL CHECK(position>=0), revision integer NOT NULL,
                expires_at timestamptz NOT NULL, created_at timestamptz NOT NULL,
                CONSTRAINT fk_playback_transfer_session FOREIGN KEY(session_id,user_id) REFERENCES listening_sessions(id,user_id) ON DELETE CASCADE,
                CONSTRAINT fk_playback_transfer_source FOREIGN KEY(source_incarnation_id,user_id) REFERENCES playback_destination_incarnations(id,user_id),
                CONSTRAINT fk_playback_transfer_target FOREIGN KEY(target_incarnation_id,user_id) REFERENCES playback_destination_incarnations(id,user_id),
                CONSTRAINT uniq_playback_transfer_request UNIQUE(session_id,request_id),
                CONSTRAINT uniq_playback_transfer_owner UNIQUE(id,user_id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_playback_current_transfer ON playback_transfers(session_id)
            WHERE state NOT IN ('committed','failed')
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE playback_commands (
                id uuid PRIMARY KEY, session_id uuid NOT NULL, user_id uuid NOT NULL,
                attempt_id uuid NOT NULL, request_id uuid NOT NULL, request_digest text NOT NULL, incarnation_id uuid,
                epoch integer, revision integer NOT NULL,
                type text NOT NULL CHECK(type IN ('prepare','quiesce','start','stop','play','pause','seek','load','observe','volume','queue')),
                payload jsonb NOT NULL, outcome text, acknowledged_at timestamptz,
                CONSTRAINT chk_playback_command_authority CHECK (
                    (incarnation_id IS NULL AND epoch IS NULL AND outcome IS NOT NULL AND outcome='retained' AND acknowledged_at IS NOT NULL AND type IN ('load','play','pause','seek','stop'))
                    OR (incarnation_id IS NOT NULL AND epoch IS NOT NULL AND epoch>0)
                ),
                expires_at timestamptz NOT NULL, created_at timestamptz NOT NULL,
                CONSTRAINT fk_playback_command_session FOREIGN KEY(session_id,user_id) REFERENCES listening_sessions(id,user_id) ON DELETE CASCADE,
                CONSTRAINT fk_playback_command_endpoint FOREIGN KEY(incarnation_id,user_id) REFERENCES playback_destination_incarnations(id,user_id),
                CONSTRAINT uniq_playback_command_request UNIQUE(session_id,request_id,type,incarnation_id,revision)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_playback_commands_replay ON playback_commands(incarnation_id,created_at) WHERE acknowledged_at IS NULL
            SQL);
        $this->addSql("CREATE INDEX idx_playback_pending_controls ON playback_commands(session_id,created_at) WHERE acknowledged_at IS NULL AND type IN ('play','pause','seek','load','stop','volume','queue')");
        $this->addSql('CREATE INDEX idx_playback_selected_destination ON listening_sessions(selected_destination_id,user_id)');
        $this->addSql('CREATE INDEX idx_playback_active_incarnation ON listening_sessions(active_incarnation_id,user_id)');
        $this->addSql(<<<'SQL'
            INSERT INTO playback_destinations(id,public_id,user_id,device_id,endpoint_id,name,kind,created_at)
            SELECT d.id,d.public_id,d.user_id,d.device_id,NULL,coalesce(d.name,'Browser'),'browser',d.created_at
            FROM devices d JOIN listening_sessions s ON s.user_id=d.user_id AND s.active_device_id=d.device_id
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE listening_sessions s SET selected_destination_id=d.id
            FROM playback_destinations d WHERE d.user_id=s.user_id AND d.device_id=s.active_device_id
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Playback coordination history and endpoint acknowledgements cannot be reconstructed after removal.');
    }
}
