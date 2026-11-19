<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119130000 extends AbstractMigration
{
    private const string CLIENT_COLUMNS = 'id,public_id,name,provider,redirect,personal_access_client,password_client,device_client,confidential,first_party,token_endpoint_auth_method,jwks_uri,revoked,user_id,created_at';

    public function getDescription(): string
    {
        return 'Store each OAuth client\'s authentication method and the key source of clients that sign their own assertions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql("ALTER TABLE oauth_clients ADD token_endpoint_auth_method TEXT DEFAULT 'none'");
        $this->addSql('ALTER TABLE oauth_clients ADD jwks JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE oauth_clients ADD jwks_uri TEXT DEFAULT NULL');

        // Every existing confidential client holds a secret. The capture triggers still name
        // the old columns here, so the backfill publishes no live update.
        $this->addSql(<<<'SQL'
            UPDATE oauth_clients
               SET token_endpoint_auth_method = CASE WHEN secret_hash IS NOT NULL THEN 'client_secret_post' ELSE 'none' END
            SQL);
        $this->addSql('ALTER TABLE oauth_clients ALTER token_endpoint_auth_method SET NOT NULL');

        $this->addSql('ALTER TABLE oauth_clients DROP CONSTRAINT chk_oauth_clients_confidential_secret_hash');
        $this->addSql(<<<'SQL'
            ALTER TABLE oauth_clients
                ADD CONSTRAINT chk_oauth_clients_token_endpoint_auth_method
                    CHECK (token_endpoint_auth_method IN ('none', 'client_secret_post', 'private_key_jwt')),
                ADD CONSTRAINT chk_oauth_clients_confidential_auth_method
                    CHECK (confidential = (token_endpoint_auth_method <> 'none')),
                ADD CONSTRAINT chk_oauth_clients_secret_hash_auth_method
                    CHECK ((secret_hash IS NOT NULL) = (token_endpoint_auth_method = 'client_secret_post')),
                ADD CONSTRAINT chk_oauth_clients_key_source_auth_method
                    CHECK (num_nonnulls(jwks, jwks_uri) = (token_endpoint_auth_method = 'private_key_jwt')::integer)
            SQL);

        $arguments = "'{" . self::CLIENT_COLUMNS . "}', '{account,admin-users}', '{user,admin}'";
        $this->addSql("CREATE OR REPLACE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON oauth_clients FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
        $this->addSql("CREATE OR REPLACE TRIGGER query_capture_insert AFTER INSERT ON oauth_clients FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Dropping the authentication method would leave clients that sign their own assertions without the keys they authenticate with.');
    }
}
