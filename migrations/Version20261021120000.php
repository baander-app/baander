<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retain the last observed availability of indexed library source files.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql('ALTER TABLE library_file_index ADD available boolean NOT NULL DEFAULT true');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Source availability cannot be downgraded without discarding missing-file observations.');
    }
}
