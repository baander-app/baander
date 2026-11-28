<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127194100 extends AbstractMigration
{
    private const string MOVIE_COLUMNS = 'id,public_id,library_id,title,year,summary,tmdb_id,imdb_id,overview,tagline,poster_url,backdrop_url,runtime,rating,original_language,tmdb_collection_id,collection_name,locked_fields,created_at';

    public function getDescription(): string
    {
        return 'Store the fields locked by an administrator on a movie.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql("ALTER TABLE movies ADD locked_fields JSONB NOT NULL DEFAULT '[]'");
        $arguments = "'{" . self::MOVIE_COLUMNS . "}', '{catalog,libraries,activity,recommendations,admin-media,admin-metadata}', '{library}'";
        $this->addSql("CREATE OR REPLACE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON movies FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
        $this->addSql("CREATE OR REPLACE TRIGGER query_capture_insert AFTER INSERT ON movies FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing movie field locks would discard administrator choices.');
    }
}
