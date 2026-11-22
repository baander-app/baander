<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121194700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Register television series, seasons and episodes with scoped Person credits.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql('CREATE TABLE tv_series (id UUID NOT NULL, public_id TEXT NOT NULL, library_id UUID NOT NULL, tmdb_id INT NOT NULL, name TEXT NOT NULL, overview TEXT DEFAULT NULL, poster_url TEXT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_tv_series_public_id ON tv_series (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tv_series_library_id_tmdb_id ON tv_series (library_id, tmdb_id)');
        $this->addSql('ALTER TABLE tv_series ADD CONSTRAINT fk_tv_series_library_id FOREIGN KEY (library_id) REFERENCES libraries (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE tv_seasons (id UUID NOT NULL, public_id TEXT NOT NULL, series_id UUID NOT NULL, season_number INT NOT NULL, PRIMARY KEY (id), CONSTRAINT chk_tv_seasons_season_number CHECK (season_number >= 0))');
        $this->addSql('CREATE UNIQUE INDEX uniq_tv_seasons_public_id ON tv_seasons (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tv_seasons_series_id_season_number ON tv_seasons (series_id, season_number)');
        $this->addSql('ALTER TABLE tv_seasons ADD CONSTRAINT fk_tv_seasons_series_id FOREIGN KEY (series_id) REFERENCES tv_series (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE tv_episodes (id UUID NOT NULL, public_id TEXT NOT NULL, season_id UUID NOT NULL, episode_number INT NOT NULL, tmdb_id INT NOT NULL, name TEXT NOT NULL, overview TEXT DEFAULT NULL, still_url TEXT DEFAULT NULL, air_date DATE DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT chk_tv_episodes_episode_number CHECK (episode_number > 0))');
        $this->addSql('CREATE UNIQUE INDEX uniq_tv_episodes_public_id ON tv_episodes (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tv_episodes_season_id_episode_number ON tv_episodes (season_id, episode_number)');
        $this->addSql('ALTER TABLE tv_episodes ADD CONSTRAINT fk_tv_episodes_season_id FOREIGN KEY (season_id) REFERENCES tv_seasons (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE series_person_credits (id UUID NOT NULL, series_id UUID NOT NULL, person_id UUID NOT NULL, category TEXT NOT NULL, role TEXT NOT NULL, job TEXT NOT NULL, character_name TEXT DEFAULT NULL, department TEXT DEFAULT NULL, credited_name TEXT DEFAULT NULL, billing_order INT DEFAULT NULL, provider_credit_id TEXT DEFAULT NULL, credit_key TEXT NOT NULL, PRIMARY KEY (id), CONSTRAINT chk_series_person_credits_category CHECK (category IN (\'cast\', \'crew\')))');
        $this->addSql('CREATE UNIQUE INDEX uniq_series_person_credits_series_id_credit_key ON series_person_credits (series_id, credit_key)');
        $this->addSql('CREATE INDEX idx_series_person_credits_person_id ON series_person_credits (person_id)');
        $this->addSql('ALTER TABLE series_person_credits ADD CONSTRAINT fk_series_person_credits_series_id FOREIGN KEY (series_id) REFERENCES tv_series (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE series_person_credits ADD CONSTRAINT fk_series_person_credits_person_id FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE series_person_credit_state (series_id UUID NOT NULL, cast_initialized BOOLEAN NOT NULL DEFAULT FALSE, crew_initialized BOOLEAN NOT NULL DEFAULT FALSE, PRIMARY KEY (series_id))');
        $this->addSql('ALTER TABLE series_person_credit_state ADD CONSTRAINT fk_series_person_credit_state_series_id FOREIGN KEY (series_id) REFERENCES tv_series (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE episode_person_credits (id UUID NOT NULL, episode_id UUID NOT NULL, person_id UUID NOT NULL, category TEXT NOT NULL, role TEXT NOT NULL, job TEXT NOT NULL, character_name TEXT DEFAULT NULL, department TEXT DEFAULT NULL, credited_name TEXT DEFAULT NULL, billing_order INT DEFAULT NULL, provider_credit_id TEXT DEFAULT NULL, credit_key TEXT NOT NULL, PRIMARY KEY (id), CONSTRAINT chk_episode_person_credits_category CHECK (category IN (\'cast\', \'crew\')))');
        $this->addSql('CREATE UNIQUE INDEX uniq_episode_person_credits_episode_id_credit_key ON episode_person_credits (episode_id, credit_key)');
        $this->addSql('CREATE INDEX idx_episode_person_credits_person_id ON episode_person_credits (person_id)');
        $this->addSql('ALTER TABLE episode_person_credits ADD CONSTRAINT fk_episode_person_credits_episode_id FOREIGN KEY (episode_id) REFERENCES tv_episodes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE episode_person_credits ADD CONSTRAINT fk_episode_person_credits_person_id FOREIGN KEY (person_id) REFERENCES people (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE episode_person_credit_state (episode_id UUID NOT NULL, cast_initialized BOOLEAN NOT NULL DEFAULT FALSE, crew_initialized BOOLEAN NOT NULL DEFAULT FALSE, PRIMARY KEY (episode_id))');
        $this->addSql('ALTER TABLE episode_person_credit_state ADD CONSTRAINT fk_episode_person_credit_state_episode_id FOREIGN KEY (episode_id) REFERENCES tv_episodes (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing television storage would discard registered titles and Person credits.');
    }
}
