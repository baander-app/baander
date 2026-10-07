<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006320000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Key password reset tokens by user, store only a token hash, and require an expiry.';
    }

    public function up(Schema $schema): void
    {
        // Rows keyed by email outlived account deletion and email changes, and held the raw
        // token. No issued token was ever delivered to anyone, so outstanding rows are
        // disposable: drop the table instead of backfilling user_id from users.email.
        $this->addSql('DROP TABLE password_reset_tokens');
        $this->addSql(<<<'SQL'
            CREATE TABLE password_reset_tokens (
                user_id UUID NOT NULL,
                token_hash TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                expires_at TIMESTAMPTZ NOT NULL,
                PRIMARY KEY (user_id)
            )
            SQL);
        // The primary key serves the cascade from users; the unique index serves redemption.
        $this->addSql('CREATE UNIQUE INDEX uniq_password_reset_tokens_token_hash ON password_reset_tokens (token_hash)');
        $this->addSql('ALTER TABLE password_reset_tokens ADD CONSTRAINT fk_password_reset_tokens_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Outstanding tokens are not carried back; a user requests a new one.
        $this->addSql('DROP TABLE password_reset_tokens');
        $this->addSql(<<<'SQL'
            CREATE TABLE password_reset_tokens (
                email CITEXT NOT NULL,
                token TEXT NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (email)
            )
            SQL);
    }
}
