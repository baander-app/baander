<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121194500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generalize director identities into People and preserve movie credits and public identities.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql(<<<'SQL'
            CREATE TABLE people (
                id UUID NOT NULL,
                public_id TEXT NOT NULL,
                name TEXT NOT NULL,
                profile_url TEXT DEFAULT NULL,
                image_id UUID DEFAULT NULL,
                biography TEXT DEFAULT NULL,
                aliases JSONB NOT NULL DEFAULT '[]',
                birth_date DATE DEFAULT NULL,
                birth_precision SMALLINT DEFAULT NULL,
                death_date DATE DEFAULT NULL,
                death_precision SMALLINT DEFAULT NULL,
                is_deceased BOOLEAN NOT NULL DEFAULT FALSE,
                birthplace TEXT DEFAULT NULL,
                sex TEXT DEFAULT NULL,
                gender TEXT DEFAULT NULL,
                profile_overrides JSONB NOT NULL DEFAULT '{}',
                PRIMARY KEY (id),
                CONSTRAINT chk_people_birth_precision CHECK (
                    (birth_date IS NULL AND birth_precision IS NULL)
                    OR (birth_date IS NOT NULL AND birth_precision IS NOT NULL AND birth_precision IN (1, 2, 3))
                ),
                CONSTRAINT chk_people_death_precision CHECK (
                    (death_date IS NULL AND death_precision IS NULL)
                    OR (death_date IS NOT NULL AND death_precision IS NOT NULL AND death_precision IN (1, 2, 3))
                )
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_people_public_id ON people (public_id)');
        $this->addSql('CREATE INDEX idx_people_image_id ON people (image_id)');
        $this->addSql('ALTER TABLE people ADD CONSTRAINT fk_people_image_id FOREIGN KEY (image_id) REFERENCES images (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql(<<<'SQL'
            CREATE TABLE person_external_ids (
                namespace TEXT NOT NULL,
                external_id TEXT NOT NULL,
                person_id UUID NOT NULL,
                source TEXT NOT NULL,
                profile_fetched_at TIMESTAMPTZ DEFAULT NULL,
                PRIMARY KEY (namespace, external_id),
                CONSTRAINT chk_person_external_ids_namespace CHECK (
                    namespace IN ('tmdb_person', 'musicbrainz_artist', 'apple_music_artist', 'imdb_name')
                ),
                CONSTRAINT chk_person_external_ids_external_id CHECK (external_id <> '')
            )
            SQL);
        $this->addSql('CREATE INDEX idx_person_external_ids_person_id ON person_external_ids (person_id)');
        $this->addSql('ALTER TABLE person_external_ids ADD CONSTRAINT fk_person_external_ids_person_id FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql(<<<'SQL'
            CREATE TABLE movie_person_credits (
                id UUID NOT NULL,
                movie_id UUID NOT NULL,
                person_id UUID NOT NULL,
                category TEXT NOT NULL,
                role TEXT NOT NULL,
                job TEXT NOT NULL,
                character_name TEXT DEFAULT NULL,
                department TEXT DEFAULT NULL,
                credited_name TEXT DEFAULT NULL,
                billing_order INTEGER DEFAULT NULL,
                provider_credit_id TEXT DEFAULT NULL,
                credit_key TEXT NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT chk_movie_person_credits_category CHECK (category IN ('cast', 'crew')),
                CONSTRAINT chk_movie_person_credits_role CHECK (role <> ''),
                CONSTRAINT chk_movie_person_credits_credit_key CHECK (credit_key <> '')
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_movie_person_credits_movie_id_credit_key ON movie_person_credits (movie_id, credit_key)');
        $this->addSql('CREATE INDEX idx_movie_person_credits_person_id ON movie_person_credits (person_id)');
        $this->addSql('ALTER TABLE movie_person_credits ADD CONSTRAINT fk_movie_person_credits_movie_id FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE movie_person_credits ADD CONSTRAINT fk_movie_person_credits_person_id FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql(<<<'SQL'
            CREATE TABLE movie_person_credit_state (
                movie_id UUID NOT NULL,
                cast_initialized BOOLEAN NOT NULL DEFAULT FALSE,
                crew_initialized BOOLEAN NOT NULL DEFAULT FALSE,
                directors_initialized BOOLEAN NOT NULL DEFAULT FALSE,
                PRIMARY KEY (movie_id)
            )
            SQL);
        $this->addSql('ALTER TABLE movie_person_credit_state ADD CONSTRAINT fk_movie_person_credit_state_movie_id FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('INSERT INTO people (id, public_id, name, profile_url) SELECT id, public_id, name, profile_url FROM movie_directors');
        $this->addSql("INSERT INTO person_external_ids (namespace, external_id, person_id, source) SELECT 'tmdb_person', tmdb_id::text, id, 'tmdb' FROM movie_directors");
        $this->addSql(<<<'SQL'
            INSERT INTO movie_person_credits (id, movie_id, person_id, category, role, job, credit_key)
            SELECT uuidv7(), movie_id, director_id, 'crew', 'director', 'Director', 'director:' || director_id::text
            FROM movie_director_credits
            SQL);
        $this->addSql('INSERT INTO movie_person_credit_state (movie_id, directors_initialized) SELECT DISTINCT movie_id, TRUE FROM movie_director_credits');
        $this->addSql('DROP TABLE movie_director_credits');
        $this->addSql('DROP TABLE movie_directors');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Restoring director-only storage would discard People and their cross-media credits.');
    }
}
