<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the email verification token table and the PKCE columns on oauth_auth_codes.
 *
 * These entities/columns were introduced in the Auth & OAuth review round but
 * had no corresponding migration, so fresh databases built from migrations were
 * missing them.
 */
final class Version920260715CreateEmailVerificationAndPkceColumns extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create email_verification_tokens table and add PKCE columns to oauth_auth_codes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS email_verification_tokens (
            id UUID NOT NULL,
            token TEXT NOT NULL,
            expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            user_id UUID NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS email_verification_tokens_token_unique ON email_verification_tokens (token)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_email_verification_tokens_user_id ON email_verification_tokens (user_id)');
        $this->addSql('ALTER TABLE email_verification_tokens
            ADD CONSTRAINT fk_email_verification_tokens_user_id
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE oauth_auth_codes ADD COLUMN IF NOT EXISTS code_challenge TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE oauth_auth_codes ADD COLUMN IF NOT EXISTS code_challenge_method VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth_auth_codes DROP COLUMN IF EXISTS code_challenge');
        $this->addSql('ALTER TABLE oauth_auth_codes DROP COLUMN IF EXISTS code_challenge_method');

        $this->addSql('ALTER TABLE email_verification_tokens DROP CONSTRAINT IF EXISTS fk_email_verification_tokens_user_id');
        $this->addSql('DROP INDEX IF EXISTS idx_email_verification_tokens_user_id');
        $this->addSql('DROP INDEX IF EXISTS email_verification_tokens_token_unique');
        $this->addSql('DROP TABLE IF EXISTS email_verification_tokens');
    }
}
