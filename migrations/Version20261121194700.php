<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121194700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create television libraries, video placements and scoped Person credits.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql(<<<'SQL'
            CREATE TABLE tv_series (
                id UUID NOT NULL,
                public_id TEXT NOT NULL,
                library_id UUID NOT NULL,
                folder_path TEXT NOT NULL,
                name TEXT NOT NULL,
                year INT DEFAULT NULL,
                matched_provider TEXT DEFAULT NULL,
                provider_id TEXT DEFAULT NULL,
                external_ids JSONB NOT NULL DEFAULT '[]',
                ordering TEXT NOT NULL DEFAULT 'aired',
                hand_matched BOOLEAN NOT NULL DEFAULT FALSE,
                overview TEXT DEFAULT NULL,
                poster_url TEXT DEFAULT NULL,
                backdrop_url TEXT DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT chk_tv_series_provider CHECK ((matched_provider IS NULL) = (provider_id IS NULL)),
                CONSTRAINT chk_tv_series_ordering CHECK (ordering IN ('aired', 'absolute', 'dvd'))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_tv_series_public_id ON tv_series (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tv_series_library_id_folder_path ON tv_series (library_id, folder_path)');
        $this->addSql('ALTER TABLE tv_series ADD CONSTRAINT fk_tv_series_library_id FOREIGN KEY (library_id) REFERENCES libraries (id) ON DELETE CASCADE');
        $this->addSql(<<<'SQL'
            CREATE TABLE tv_seasons (
                id UUID NOT NULL,
                public_id TEXT NOT NULL,
                series_id UUID NOT NULL,
                season_number INT NOT NULL,
                name TEXT DEFAULT NULL,
                overview TEXT DEFAULT NULL,
                poster_url TEXT DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT chk_tv_seasons_season_number CHECK (season_number >= 0)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_tv_seasons_public_id ON tv_seasons (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tv_seasons_series_id_season_number ON tv_seasons (series_id, season_number)');
        $this->addSql('ALTER TABLE tv_seasons ADD CONSTRAINT fk_tv_seasons_series_id FOREIGN KEY (series_id) REFERENCES tv_series (id) ON DELETE CASCADE');
        $this->addSql(<<<'SQL'
            CREATE TABLE tv_episodes (
                id UUID NOT NULL,
                public_id TEXT NOT NULL,
                series_id UUID NOT NULL,
                season_id UUID DEFAULT NULL,
                episode_number INT DEFAULT NULL,
                absolute_number INT DEFAULT NULL,
                provider_id TEXT DEFAULT NULL,
                external_ids JSONB NOT NULL DEFAULT '[]',
                name TEXT NOT NULL,
                overview TEXT DEFAULT NULL,
                still_url TEXT DEFAULT NULL,
                air_date DATE DEFAULT NULL,
                retired BOOLEAN NOT NULL DEFAULT FALSE,
                PRIMARY KEY (id),
                CONSTRAINT chk_tv_episodes_numbering CHECK (
                    (season_id IS NULL) = (episode_number IS NULL)
                    AND (episode_number IS NULL OR episode_number > 0)
                    AND (absolute_number IS NULL OR absolute_number > 0)
                )
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_tv_episodes_public_id ON tv_episodes (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tv_episodes_season_id_episode_number ON tv_episodes (season_id, episode_number)');
        $this->addSql('CREATE INDEX idx_tv_episodes_series_id ON tv_episodes (series_id)');
        $this->addSql('ALTER TABLE tv_episodes ADD CONSTRAINT fk_tv_episodes_series_id FOREIGN KEY (series_id) REFERENCES tv_series (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tv_episodes ADD CONSTRAINT fk_tv_episodes_season_id FOREIGN KEY (season_id) REFERENCES tv_seasons (id) ON DELETE CASCADE');
        $this->addSql(<<<'SQL'
            CREATE TABLE video_placements (
                id UUID NOT NULL,
                public_id TEXT NOT NULL,
                library_id UUID NOT NULL,
                video_id UUID NOT NULL,
                series_id UUID DEFAULT NULL,
                movie_id UUID DEFAULT NULL,
                relative_path TEXT NOT NULL,
                raw_parse JSONB NOT NULL DEFAULT '[]',
                extra_kind TEXT DEFAULT NULL,
                assigned BOOLEAN NOT NULL DEFAULT FALSE,
                review_reason TEXT DEFAULT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMPTZ NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT chk_video_placements_owner CHECK ((series_id IS NOT NULL)::int + (movie_id IS NOT NULL)::int = 1)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_video_placements_public_id ON video_placements (public_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_video_placements_library_id_video_id ON video_placements (library_id, video_id)');
        $this->addSql('CREATE INDEX idx_video_placements_video_id ON video_placements (video_id)');
        $this->addSql('CREATE INDEX idx_video_placements_series_id ON video_placements (series_id)');
        $this->addSql('CREATE INDEX idx_video_placements_movie_id ON video_placements (movie_id)');
        $this->addSql('ALTER TABLE video_placements ADD CONSTRAINT fk_video_placements_library_id FOREIGN KEY (library_id) REFERENCES libraries (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE video_placements ADD CONSTRAINT fk_video_placements_video_id FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE video_placements ADD CONSTRAINT fk_video_placements_series_id FOREIGN KEY (series_id) REFERENCES tv_series (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE video_placements ADD CONSTRAINT fk_video_placements_movie_id FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE CASCADE');
        $this->addSql(<<<'SQL'
            CREATE TABLE video_placement_episode (
                id UUID NOT NULL,
                placement_id UUID NOT NULL,
                episode_id UUID NOT NULL,
                sort_order INT NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_video_placement_episode_placement_id_episode_id ON video_placement_episode (placement_id, episode_id)');
        $this->addSql('CREATE INDEX idx_video_placement_episode_episode_id ON video_placement_episode (episode_id)');
        $this->addSql('ALTER TABLE video_placement_episode ADD CONSTRAINT fk_video_placement_episode_placement_id FOREIGN KEY (placement_id) REFERENCES video_placements (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE video_placement_episode ADD CONSTRAINT fk_video_placement_episode_episode_id FOREIGN KEY (episode_id) REFERENCES tv_episodes (id) ON DELETE CASCADE');
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
        $this->throwIrreversibleMigrationException('Removing television storage would discard titles, placements and Person credits.');
    }
}
