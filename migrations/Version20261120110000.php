<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fence token issuance with a per-user credential epoch, store refresh tokens as SHA-256 hashes and cap each refresh chain with an absolute expiry.';
    }

    public function up(Schema $schema): void
    {
        // Every ALTER below takes ACCESS EXCLUSIVE on a table that each API request reads; fail
        // fast rather than queue behind a running request. Adding a column with a constant
        // default (users) or to an emptied table rewrites nothing.
        $this->addSql("SET LOCAL lock_timeout = '2s'");

        // Stored refresh tokens are raw values and carry no epoch or chain expiry. Deleting the
        // access tokens cascades to their refresh tokens and metadata, which ends every session;
        // the capture triggers record the deletions for live clients. Authorization codes live
        // for a minute and cannot be given the epoch of the token that approved them.
        $this->addSql('DELETE FROM oauth_access_tokens');
        $this->addSql('DELETE FROM oauth_auth_codes');

        // Incremented by every password change. Issuance compares the epoch captured where the
        // credential was proved with this value under the row lock and refuses when it moved.
        $this->addSql('ALTER TABLE users ADD credential_epoch INTEGER DEFAULT 0 NOT NULL');

        // The epoch a token, chain or code was issued under. No default: each writer stamps the
        // epoch it captured. A device code gets the approving token's epoch when it is approved.
        $this->addSql('ALTER TABLE oauth_access_tokens ADD credential_epoch INTEGER NOT NULL');
        $this->addSql('ALTER TABLE oauth_refresh_tokens ADD credential_epoch INTEGER NOT NULL');
        $this->addSql('ALTER TABLE oauth_auth_codes ADD credential_epoch INTEGER NOT NULL');
        $this->addSql('ALTER TABLE oauth_device_codes ADD credential_epoch INTEGER DEFAULT NULL');

        // token_id now holds the hex SHA-256 digest of the refresh token; the token itself is
        // never stored. chain_expires_at is set when a chain starts and copied by each rotation.
        $this->addSql('ALTER TABLE oauth_refresh_tokens ADD chain_expires_at TIMESTAMPTZ NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The deleted sessions and authorization codes cannot be restored, and removing the epoch would let sessions outlive a password change.');
    }
}
