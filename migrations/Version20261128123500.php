<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128123500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow TheTVDB person identifiers on the shared Person identity.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql('ALTER TABLE person_external_ids DROP CONSTRAINT chk_person_external_ids_namespace');
        $this->addSql("ALTER TABLE person_external_ids ADD CONSTRAINT chk_person_external_ids_namespace CHECK (namespace IN ('tmdb_person', 'tvdb_person', 'musicbrainz_artist', 'apple_music_artist', 'imdb_name'))");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('TheTVDB person identifiers cannot be discarded without losing shared identities.');
    }
}
