<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add metadata columns to the movies table that were introduced in MovieEntity
 * but missing from the initial schema migration.
 */
final class Version20260715172602 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing metadata columns to movies table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE movies ADD COLUMN IF NOT EXISTS tmdb_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE movies ADD COLUMN IF NOT EXISTS imdb_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE movies ADD COLUMN IF NOT EXISTS overview TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE movies ADD COLUMN IF NOT EXISTS tagline VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE movies ADD COLUMN IF NOT EXISTS poster_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE movies ADD COLUMN IF NOT EXISTS backdrop_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE movies ADD COLUMN IF NOT EXISTS runtime INT DEFAULT NULL');
        $this->addSql('ALTER TABLE movies ADD COLUMN IF NOT EXISTS rating DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE movies ADD COLUMN IF NOT EXISTS original_language VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE movies ADD COLUMN IF NOT EXISTS tmdb_collection_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE movies ADD COLUMN IF NOT EXISTS collection_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE movies DROP COLUMN IF EXISTS tmdb_id');
        $this->addSql('ALTER TABLE movies DROP COLUMN IF EXISTS imdb_id');
        $this->addSql('ALTER TABLE movies DROP COLUMN IF EXISTS overview');
        $this->addSql('ALTER TABLE movies DROP COLUMN IF EXISTS tagline');
        $this->addSql('ALTER TABLE movies DROP COLUMN IF EXISTS poster_url');
        $this->addSql('ALTER TABLE movies DROP COLUMN IF EXISTS backdrop_url');
        $this->addSql('ALTER TABLE movies DROP COLUMN IF EXISTS runtime');
        $this->addSql('ALTER TABLE movies DROP COLUMN IF EXISTS rating');
        $this->addSql('ALTER TABLE movies DROP COLUMN IF EXISTS original_language');
        $this->addSql('ALTER TABLE movies DROP COLUMN IF EXISTS tmdb_collection_id');
        $this->addSql('ALTER TABLE movies DROP COLUMN IF EXISTS collection_name');
    }
}
