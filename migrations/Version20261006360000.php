<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006360000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Key email verification tokens by user, store only a token hash, and bind each token to the address it verifies.';
    }

    public function up(Schema $schema): void
    {
        // The old rows held the raw token and were never emailed to anyone, so nobody can
        // redeem them: drop the table instead of carrying them over. Users who registered
        // before this migration ask for a new link from the web app.
        $this->addSql('DROP TABLE email_verification_tokens');
        $this->addSql(<<<'SQL'
            CREATE TABLE email_verification_tokens (
                user_id UUID NOT NULL,
                email CITEXT NOT NULL,
                token_hash TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                expires_at TIMESTAMPTZ NOT NULL,
                PRIMARY KEY (user_id)
            )
            SQL);
        // The primary key serves the cascade from users; the unique index serves redemption.
        $this->addSql('CREATE UNIQUE INDEX uniq_email_verification_tokens_token_hash ON email_verification_tokens (token_hash)');
        $this->addSql('ALTER TABLE email_verification_tokens ADD CONSTRAINT fk_email_verification_tokens_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Outstanding tokens are not carried back; a user asks for a new link.
        $this->addSql('DROP TABLE email_verification_tokens');
        $this->addSql(<<<'SQL'
            CREATE TABLE email_verification_tokens (
                id UUID NOT NULL,
                token TEXT NOT NULL,
                expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                user_id UUID NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_email_verification_tokens_token ON email_verification_tokens (token)');
        $this->addSql('CREATE INDEX idx_email_verification_tokens_user_id ON email_verification_tokens (user_id)');
        $this->addSql('ALTER TABLE email_verification_tokens ADD CONSTRAINT fk_email_verification_tokens_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }
}
