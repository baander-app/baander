<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006300000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the unused OAuth authorization and device code tables and the personal access client columns, and delete access tokens with their user.';
    }

    public function up(Schema $schema): void
    {
        // Only the removed authorization code and device grants wrote these tables.
        $this->addSql('DROP TABLE oauth_auth_codes');
        $this->addSql('DROP TABLE oauth_device_codes');

        // Personal access and device clients could not obtain tokens once those grants were removed.
        // Their access tokens, refresh tokens and token metadata cascade.
        $this->addSql('DELETE FROM oauth_clients WHERE personal_access_client OR device_client');
        // Dropping user_id also drops fk_oauth_clients_user_id and idx_oauth_clients_user_id.
        $this->addSql('ALTER TABLE oauth_clients DROP COLUMN personal_access_client, DROP COLUMN device_client, DROP COLUMN user_id');

        // A token whose user was deleted under the former SET NULL rule authenticates no one.
        $this->addSql('DELETE FROM oauth_access_tokens WHERE user_id IS NULL');
        $this->addSql('ALTER TABLE oauth_access_tokens DROP CONSTRAINT fk_oauth_access_tokens_user_id');
        $this->addSql('ALTER TABLE oauth_access_tokens ADD CONSTRAINT fk_oauth_access_tokens_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Restores the schema only; the rows deleted by up() and the dropped tables' rows are not restored.
        $this->addSql('ALTER TABLE oauth_access_tokens DROP CONSTRAINT fk_oauth_access_tokens_user_id');
        $this->addSql('ALTER TABLE oauth_access_tokens ADD CONSTRAINT fk_oauth_access_tokens_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE oauth_clients ADD COLUMN personal_access_client BOOLEAN DEFAULT FALSE NOT NULL, ADD COLUMN device_client BOOLEAN DEFAULT FALSE NOT NULL, ADD COLUMN user_id UUID');
        $this->addSql('ALTER TABLE oauth_clients ADD CONSTRAINT fk_oauth_clients_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_oauth_clients_user_id ON oauth_clients (user_id)');

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
        $this->addSql('CREATE UNIQUE INDEX uniq_oauth_device_codes_device_code ON oauth_device_codes (device_code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_oauth_device_codes_user_code ON oauth_device_codes (user_code)');
        $this->addSql('CREATE INDEX idx_oauth_device_codes_client_id ON oauth_device_codes (client_id)');
        $this->addSql('CREATE INDEX idx_oauth_device_codes_user_id ON oauth_device_codes (user_id)');
        $this->addSql('ALTER TABLE oauth_device_codes ADD CONSTRAINT fk_oauth_device_codes_client_id FOREIGN KEY (client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth_device_codes ADD CONSTRAINT fk_oauth_device_codes_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');

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
        $this->addSql('CREATE UNIQUE INDEX uniq_oauth_auth_codes_code_id ON oauth_auth_codes (code_id)');
        $this->addSql('CREATE INDEX idx_oauth_auth_codes_client_id ON oauth_auth_codes (client_id)');
        $this->addSql('CREATE INDEX idx_oauth_auth_codes_user_id ON oauth_auth_codes (user_id)');
        $this->addSql('ALTER TABLE oauth_auth_codes ADD CONSTRAINT fk_oauth_auth_codes_client_id FOREIGN KEY (client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth_auth_codes ADD CONSTRAINT fk_oauth_auth_codes_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }
}
