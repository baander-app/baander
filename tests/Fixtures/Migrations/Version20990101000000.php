<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20990101000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE future_migration_probe (id integer PRIMARY KEY)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE future_migration_probe');
    }
}
