<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store TMDB director identities and movie credits without changing existing movies.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql('CREATE TABLE movie_directors (id UUID NOT NULL, public_id TEXT NOT NULL, tmdb_id INTEGER NOT NULL, name TEXT NOT NULL, profile_url TEXT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_movie_directors_public_id ON movie_directors (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_movie_directors_tmdb_id ON movie_directors (tmdb_id)');
        $this->addSql('CREATE TABLE movie_director_credits (movie_id UUID NOT NULL, director_id UUID NOT NULL, PRIMARY KEY (movie_id, director_id))');
        $this->addSql('CREATE INDEX idx_movie_director_credits_director_id ON movie_director_credits (director_id)');
        $this->addSql('ALTER TABLE movie_director_credits ADD CONSTRAINT fk_movie_director_credits_movie_id FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE movie_director_credits ADD CONSTRAINT fk_movie_director_credits_director_id FOREIGN KEY (director_id) REFERENCES movie_directors (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing director storage would discard imported identities and movie credits.');
    }
}
