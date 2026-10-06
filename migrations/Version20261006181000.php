<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006181000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store library access grant instants as timestamptz.';
    }

    public function up(Schema $schema): void
    {
        // Existing values were written by PHP in its default UTC zone, or by the
        // now() default in a UTC-session PostgreSQL. Name the zone so the session
        // zone cannot shift them, and reset the default so it is plain now().
        $this->addSql(<<<'SQL'
            ALTER TABLE user_library_access
                ALTER COLUMN granted_at DROP DEFAULT,
                ALTER COLUMN granted_at TYPE TIMESTAMPTZ USING granted_at AT TIME ZONE 'UTC',
                ALTER COLUMN granted_at SET DEFAULT now()
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Application writes carry whole seconds, so second precision keeps their
        // instants; only fractions from the now() default are rounded away.
        $this->addSql(<<<'SQL'
            ALTER TABLE user_library_access
                ALTER COLUMN granted_at DROP DEFAULT,
                ALTER COLUMN granted_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING granted_at AT TIME ZONE 'UTC',
                ALTER COLUMN granted_at SET DEFAULT now()
            SQL);
    }
}
