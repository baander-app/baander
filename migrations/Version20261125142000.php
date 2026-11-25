<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125142000 extends AbstractMigration
{
    private const array CHECKS = [
        'chk_push_subscriptions_endpoint_length' => 'char_length(endpoint) <= 2048',
        'chk_push_subscriptions_public_key_length' => 'char_length(public_key) <= 128',
        'chk_push_subscriptions_auth_key_length' => 'char_length(auth_key) <= 64',
        'chk_push_subscriptions_user_agent_length' => 'char_length(user_agent) <= 255',
    ];

    public function getDescription(): string
    {
        return 'Bound the lengths of a push subscription\'s endpoint, keys and User-Agent label.';
    }

    public function up(Schema $schema): void
    {
        // The subscribe route validates the endpoint and keys and cuts the User-Agent; the checks
        // hold the bounds for every writer. NOT VALID: a stored subscription is not scanned and
        // stays deliverable; an update of a row over a bound fails. The table's capture
        // triggers list its columns, which these checks leave unchanged.
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        foreach (self::CHECKS as $name => $expression) {
            $this->addSql(sprintf('ALTER TABLE push_subscriptions ADD CONSTRAINT %s CHECK (%s) NOT VALID', $name, $expression));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_keys(self::CHECKS) as $name) {
            $this->addSql(sprintf('ALTER TABLE push_subscriptions DROP CONSTRAINT %s', $name));
        }
    }
}
