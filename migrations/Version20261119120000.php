<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store pushed authorization requests and bind each authorization code to a DPoP key.';
    }

    public function up(Schema $schema): void
    {
        // The foreign key locks oauth_clients against writes while it is added, and the new
        // column locks oauth_auth_codes; neither waits long behind a running transaction.
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        // One row per request a client pushed. It has no user: the user exists only at the
        // decision, which sets consumed_at once.
        $this->addSql(<<<'SQL'
            CREATE TABLE oauth_pushed_authorization_requests (
                id uuid NOT NULL,
                request_id text NOT NULL,
                client_id uuid NOT NULL,
                redirect_uri text NOT NULL,
                scopes jsonb NOT NULL,
                state text,
                code_challenge text NOT NULL,
                code_challenge_method text NOT NULL,
                dpop_jkt text NOT NULL,
                expires_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL,
                consumed_at timestamptz,
                CONSTRAINT oauth_pushed_authorization_requests_pkey PRIMARY KEY (id),
                CONSTRAINT chk_oauth_pushed_authorization_requests_code_challenge_method CHECK (code_challenge_method = 'S256')
            )
            SQL);
        // A pushed request ends with its client.
        $this->addSql('ALTER TABLE oauth_pushed_authorization_requests ADD CONSTRAINT fk_oauth_pushed_authorization_requests_client_id FOREIGN KEY (client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE');
        // The request URI carries the request_id, so a lookup finds the row by it.
        $this->addSql('CREATE UNIQUE INDEX uniq_oauth_pushed_authorization_requests_request_id ON oauth_pushed_authorization_requests USING btree (request_id)');
        $this->addSql('CREATE INDEX idx_oauth_pushed_authorization_requests_client_id ON oauth_pushed_authorization_requests USING btree (client_id)');
        // Existing codes carry no key binding and cannot be given one; they are short-lived anyway.
        $this->addSql('DELETE FROM oauth_auth_codes');
        // The JWK SHA-256 thumbprint of the DPoP key that the token request must prove.
        $this->addSql('ALTER TABLE oauth_auth_codes ADD dpop_jkt text NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Pushed authorization requests and the key binding of authorization codes cannot be removed: dropping them loses which key each code is bound to.');
    }
}
