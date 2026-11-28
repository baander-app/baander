<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128123600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track provider-owned TV metadata and scope managed video artwork to its library.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql("ALTER TABLE tv_series ADD provider_fields JSONB NOT NULL DEFAULT '[]', ADD locked_fields JSONB NOT NULL DEFAULT '[]'");
        $this->addSql("ALTER TABLE tv_seasons ADD provider_fields JSONB NOT NULL DEFAULT '[]', ADD locked_fields JSONB NOT NULL DEFAULT '[]'");
        $this->addSql("ALTER TABLE tv_episodes ADD provider_fields JSONB NOT NULL DEFAULT '[]', ADD locked_fields JSONB NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE images ADD library_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_images_library_id ON images (library_id)');
        $this->addSql('ALTER TABLE images ADD CONSTRAINT fk_images_library_id FOREIGN KEY (library_id) REFERENCES libraries (id) ON DELETE CASCADE');
        $arguments = "'{id,path,extension,mime_type,blurhash,public_id,size,width,height,imageable_type,album_id,artist_id,playlist_id,created_at,library_id}', '{catalog,playlists,activity,admin-media}', '{image,library}'";
        $this->addSql("CREATE OR REPLACE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON images FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
        $this->addSql("CREATE OR REPLACE TRIGGER query_capture_insert AFTER INSERT ON images FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing metadata ownership or artwork scope would discard administrator choices and image access boundaries.');
    }
}
