<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record on each access token the sign-in grant that started its refresh chain.';
    }

    public function up(Schema $schema): void
    {
        // The ALTER takes ACCESS EXCLUSIVE on a table that each API request reads; fail fast
        // rather than queue behind a running request. Adding a column to an emptied table
        // rewrites nothing.
        $this->addSql("SET LOCAL lock_timeout = '2s'");

        // A stored token does not say how its chain began, and a guessed grant could give
        // first-party authority to a pair the authorization code grant issued to the first-party
        // client. Deleting the access tokens cascades to their refresh tokens and metadata, which
        // ends every session; the capture triggers record the deletions for live clients.
        $this->addSql('DELETE FROM oauth_access_tokens');

        // No default: each issuance names its grant, and a rotation copies its predecessor's.
        $this->addSql('ALTER TABLE oauth_access_tokens ADD grant_type TEXT NOT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE oauth_access_tokens
                ADD CONSTRAINT chk_oauth_access_tokens_grant_type
                    CHECK (grant_type IN ('password', 'passkey', 'authorization_code', 'device_code'))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The deleted sessions cannot be restored, and dropping the grant would let a token of the first-party client act with first-party authority whatever grant started it.');
    }
}
