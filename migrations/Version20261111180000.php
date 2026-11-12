<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record which page registered a playback endpoint incarnation, so a registration from another page of the same tab can be told from one by the same page.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql('ALTER TABLE playback_destination_incarnations ADD page_id uuid DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // An incarnation without a page identity is one that proves nothing, which is what every row is again afterwards.
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql('ALTER TABLE playback_destination_incarnations DROP page_id');
    }
}
