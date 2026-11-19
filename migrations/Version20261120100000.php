<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hold a self-service email change as a pending address until its verification link is used.';
    }

    public function up(Schema $schema): void
    {
        // Adding a nullable column without a default rewrites nothing, but the ALTER and the
        // trigger replacement need ACCESS EXCLUSIVE on users, which every request reads.
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        // The address a user asked to change to. It is not unique: an address becomes the
        // account's only when its verification link is used, and uniqueness is checked then
        // (uniq_users_email). The verification token is bound to it through
        // email_verification_tokens.email, which holds the address each token verifies.
        $this->addSql('ALTER TABLE users ADD pending_email TEXT DEFAULT NULL');

        // The account settings show the pending address, so a change to it is an account
        // change like a change of the email. The capture function is unchanged; only the
        // relevant column list the triggers pass it gains pending_email.
        $this->addSql('DROP TRIGGER query_capture_before ON users');
        $this->addSql('DROP TRIGGER query_capture_insert ON users');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON users FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,name,email,email_verified_at,pending_email,created_at,roles,disabled}', '{account,admin-users,activity}', '{self,admin}')
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER query_capture_insert AFTER INSERT ON users FOR EACH ROW EXECUTE FUNCTION query_live_capture_row('{id,public_id,name,email,email_verified_at,pending_email,created_at,roles,disabled}', '{account,admin-users,activity}', '{self,admin}')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Dropping pending_email would silently cancel email changes that users are waiting to confirm.');
    }
}
