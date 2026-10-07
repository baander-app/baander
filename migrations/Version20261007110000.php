<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store system setting update instants as timestamptz.';
    }

    public function up(Schema $schema): void
    {
        // Existing values were written by Doctrine from PHP in its default UTC zone, or by the
        // column default in a UTC-session PostgreSQL. Name the zone so the session zone cannot
        // shift them. The default is re-created for the new type rather than converted.
        $this->addSql(<<<'SQL'
            ALTER TABLE system_settings
                ALTER COLUMN updated_at DROP DEFAULT,
                ALTER COLUMN updated_at TYPE TIMESTAMPTZ USING updated_at AT TIME ZONE 'UTC',
                ALTER COLUMN updated_at SET DEFAULT now()
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Both types keep microseconds, so the instants survive as UTC wall-clock times.
        $this->addSql(<<<'SQL'
            ALTER TABLE system_settings
                ALTER COLUMN updated_at DROP DEFAULT,
                ALTER COLUMN updated_at TYPE TIMESTAMP WITHOUT TIME ZONE USING updated_at AT TIME ZONE 'UTC',
                ALTER COLUMN updated_at SET DEFAULT now()
            SQL);
    }
}
