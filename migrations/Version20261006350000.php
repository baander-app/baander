<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006350000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bind authorization codes to their redirect URI and require an S256 PKCE challenge.';
    }

    public function up(Schema $schema): void
    {
        // Authorization codes live for minutes and none was ever written with a redirect URI or a
        // challenge, so existing rows cannot be redeemed under the new rules.
        $this->addSql('DELETE FROM oauth_auth_codes');
        $this->addSql("ALTER TABLE oauth_auth_codes
            ADD COLUMN redirect_uri TEXT NOT NULL,
            ALTER COLUMN code_challenge SET NOT NULL,
            ALTER COLUMN code_challenge_method SET NOT NULL,
            ADD CONSTRAINT chk_oauth_auth_codes_code_challenge_method CHECK (code_challenge_method = 'S256')");
    }

    public function down(Schema $schema): void
    {
        // The rows deleted by up() are not restored, and the redirect URIs of later codes are lost.
        $this->addSql('ALTER TABLE oauth_auth_codes
            DROP CONSTRAINT chk_oauth_auth_codes_code_challenge_method,
            ALTER COLUMN code_challenge_method DROP NOT NULL,
            ALTER COLUMN code_challenge DROP NOT NULL,
            DROP COLUMN redirect_uri');
    }
}
