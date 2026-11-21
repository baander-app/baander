<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121194800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Claim pending TMDB Person enrichment across concurrent workers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql('ALTER TABLE person_external_ids ADD profile_claimed_until TIMESTAMPTZ DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE person_external_ids DROP profile_claimed_until');
    }
}
