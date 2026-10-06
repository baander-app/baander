<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006270000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the never-written PKCE challenge columns from oauth_auth_codes.';
    }

    public function up(Schema $schema): void
    {
        // League kept the PKCE challenge in the encrypted code and never wrote these columns,
        // and authorization codes are no longer issued. Both columns hold only NULL.
        $this->addSql('ALTER TABLE oauth_auth_codes DROP COLUMN code_challenge, DROP COLUMN code_challenge_method');
    }

    public function down(Schema $schema): void
    {
        // The dropped columns held no values, so restoring them empty loses nothing.
        $this->addSql('ALTER TABLE oauth_auth_codes ADD COLUMN code_challenge TEXT, ADD COLUMN code_challenge_method TEXT');
    }
}
