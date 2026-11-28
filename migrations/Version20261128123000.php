<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve exact provider episode ordering identifiers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql("ALTER TABLE tv_series DROP CONSTRAINT chk_tv_series_ordering, ADD CONSTRAINT chk_tv_series_ordering CHECK (btrim(ordering) <> '')");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Provider ordering identifiers cannot be reduced to the legacy ordering names.');
    }
}
