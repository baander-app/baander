<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store push subscription creation instants as timestamptz.';
    }

    public function up(Schema $schema): void
    {
        // Existing values were written by PHP in its default UTC zone into a
        // UTC-session PostgreSQL. Name the zone so the session zone cannot shift them.
        $this->addSql("ALTER TABLE push_subscriptions ALTER COLUMN created_at TYPE TIMESTAMPTZ USING created_at AT TIME ZONE 'UTC'");
    }

    public function down(Schema $schema): void
    {
        // Application writes carry whole seconds, so second precision keeps their instants.
        $this->addSql("ALTER TABLE push_subscriptions ALTER COLUMN created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING created_at AT TIME ZONE 'UTC'");
    }
}
