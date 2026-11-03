<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes UTC the default time zone of the current database, for every role and session that
 * does not set its own. ALTER DATABASE … SET is transactional and takes effect for new
 * sessions. No earlier migration or tool sets a database time zone, so down() removes the
 * setting and the server configuration's zone applies again.
 */
final class Version20261103210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set the database default time zone to UTC.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                EXECUTE format('ALTER DATABASE %I SET timezone = %L', current_database(), 'UTC');
            END
            $$
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                EXECUTE format('ALTER DATABASE %I RESET timezone', current_database());
            END
            $$
            SQL);
    }
}
