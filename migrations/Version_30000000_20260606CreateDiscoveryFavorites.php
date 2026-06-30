<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version320260606CreateDiscoveryFavorites extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create server_instances, pairing_sessions, and user_favorites tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE server_instances (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            server_url TEXT NOT NULL,
            name TEXT NOT NULL,
            api_key TEXT NOT NULL,
            version TEXT NOT NULL,
            status TEXT DEFAULT \'online\' NOT NULL,
            last_heartbeat_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX server_instances_public_id_key ON server_instances (public_id)');
        $this->addSql('CREATE UNIQUE INDEX server_instances_server_url_key ON server_instances (server_url)');

        $this->addSql('CREATE TABLE pairing_sessions (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            server_id UUID NOT NULL,
            server_public_id TEXT NOT NULL,
            server_url TEXT NOT NULL,
            server_name TEXT NOT NULL,
            pairing_code TEXT NOT NULL,
            method TEXT NOT NULL,
            expires_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            completed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            expired_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX pairing_sessions_public_id_key ON pairing_sessions (public_id)');
        $this->addSql('CREATE UNIQUE INDEX pairing_sessions_pairing_code_key ON pairing_sessions (pairing_code)');

        $this->addSql('CREATE TABLE user_favorites (
            id UUID NOT NULL,
            public_id TEXT NOT NULL,
            user_id UUID NOT NULL,
            entity_type TEXT NOT NULL,
            entity_public_id TEXT NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX user_favorites_public_id_key ON user_favorites (public_id)');
        $this->addSql('CREATE UNIQUE INDEX user_favorites_user_entity_key ON user_favorites (user_id, entity_type, entity_public_id)');
        $this->addSql('CREATE INDEX user_favorites_user_id_idx ON user_favorites (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE server_instances');
        $this->addSql('DROP TABLE pairing_sessions');
        $this->addSql('DROP TABLE user_favorites');
    }
}
