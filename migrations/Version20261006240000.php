<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the user_sidebar_configs user_id index that the unique (user_id, media_type) index already covers.';
    }

    public function up(Schema $schema): void
    {
        // uniq_user_sidebar_configs_user_id_media_type leads with user_id, so it serves owner lookups and the
        // fk_user_sidebar_configs_user_id cascade.
        $this->addSql('DROP INDEX idx_user_sidebar_configs_user_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_user_sidebar_configs_user_id ON user_sidebar_configs (user_id)');
    }
}
