<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125090307 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bound each stored field of a login block to 255 characters and index blocks by age for the retention delete.';
    }

    public function up(Schema $schema): void
    {
        // Login blocks are written only by the sign-in honeypot, at the per-address sign-in rate.
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        // Every field is sender input, stored without a bound before this migration. Each keeps its
        // first 255 characters; no block is deleted. left() counts characters, as the checks do.
        $this->addSql(<<<'SQL'
            UPDATE login_blocks
            SET ip_address = left(ip_address, 255),
                email = left(email, 255),
                field_value = left(field_value, 255),
                user_agent = left(user_agent, 255)
            WHERE char_length(ip_address) > 255
               OR char_length(email) > 255
               OR char_length(field_value) > 255
               OR char_length(user_agent) > 255
            SQL);
        // One ALTER TABLE validates all four checks in a single scan.
        $this->addSql(<<<'SQL'
            ALTER TABLE login_blocks
                ADD CONSTRAINT chk_login_blocks_ip_address_length CHECK (char_length(ip_address) <= 255),
                ADD CONSTRAINT chk_login_blocks_email_length CHECK (char_length(email) <= 255),
                ADD CONSTRAINT chk_login_blocks_field_value_length CHECK (char_length(field_value) <= 255),
                ADD CONSTRAINT chk_login_blocks_user_agent_length CHECK (char_length(user_agent) <= 255)
            SQL);
        // Storing a block deletes those past retention by created_at alone, which the existing
        // (ip_address, created_at) index cannot serve as a range; the admin list orders by it too.
        $this->addSql('CREATE INDEX idx_login_blocks_created_at ON login_blocks USING btree (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The cut field values cannot be restored, and the bound keeps a sign-in attempt from storing more.');
    }
}
