<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bound the state of pushed authorization requests and record the token chain each authorization code started.';
    }

    public function up(Schema $schema): void
    {
        // Both tables are small and short-lived; neither change waits long behind a running transaction.
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        // A client pushes state of at most 1024 bytes; the endpoint refuses longer values, and the
        // constraint keeps an unauthenticated caller from storing more through any other path.
        // Requests holding a longer state were pushed before the bound and live for minutes.
        $this->addSql('DELETE FROM oauth_pushed_authorization_requests WHERE octet_length(state) > 1024');
        $this->addSql('ALTER TABLE oauth_pushed_authorization_requests ADD CONSTRAINT chk_oauth_pushed_authorization_requests_state_length CHECK (octet_length(state) <= 1024)');
        // Set when the code is redeemed; presenting the redeemed code again revokes this chain.
        $this->addSql('ALTER TABLE oauth_auth_codes ADD chain_id uuid DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Dropping the chain of redeemed authorization codes would let a replayed code leave its tokens live, and the state bound guards the pushed request store.');
    }
}
