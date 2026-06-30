<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates entity tables that had ORM mappings but no migration.
 *
 * Same gap class as domain_event_outbox / user_theme_moods / user_favorites:
 * entities were added without a corresponding migration, so any DB built purely
 * from migrations (test env, fresh installs) is missing them and throws
 * TableNotFoundException the moment that context is exercised.
 *
 * Discovered by diffing #[ORM\Table] names against the live schema after a
 * clean migrate. Tables: login_blocks, genre_movie, library_file_index,
 * movie_collections, party_events, scheduled_jobs.
 *
 * DDL follows the conventions of Version001_InitialSchema (UUID/TEXT/JSONB,
 * TIMESTAMP(0) WITH TIME ZONE). Idempotent via IF NOT EXISTS so re-running
 * after a filtered schema drop (app:dev:setup --fresh) never collides.
 */
final class Version620260615CreateMissingEntityTables extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create login_blocks, genre_movie, library_file_index, movie_collections, party_events, scheduled_jobs tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS login_blocks (
            id UUID NOT NULL,
            ip_address TEXT NOT NULL,
            email TEXT NOT NULL,
            field_value TEXT NOT NULL,
            user_agent TEXT NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_login_blocks_ip_created ON login_blocks (ip_address, created_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_login_blocks_email ON login_blocks (email)');

        $this->addSql('CREATE TABLE IF NOT EXISTS genre_movie (
            id UUID NOT NULL,
            genre_id UUID NOT NULL,
            movie_id UUID NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS genre_movie_unique ON genre_movie (genre_id, movie_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_genre_movie_genre_id ON genre_movie (genre_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_genre_movie_movie_id ON genre_movie (movie_id)');
        $this->addSql('ALTER TABLE genre_movie ADD CONSTRAINT genre_movie_genre_id_fkey FOREIGN KEY (genre_id) REFERENCES genres (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE genre_movie ADD CONSTRAINT genre_movie_movie_id_fkey FOREIGN KEY (movie_id) REFERENCES movies (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE IF NOT EXISTS library_file_index (
            id UUID NOT NULL,
            library_id UUID NOT NULL,
            path TEXT NOT NULL,
            hash TEXT NOT NULL,
            size INT NOT NULL,
            extension TEXT NOT NULL,
            modified_at INT NOT NULL,
            discovered_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS library_file_path_unique ON library_file_index (library_id, path)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_library_file_index_library_id ON library_file_index (library_id)');

        $this->addSql('CREATE TABLE IF NOT EXISTS movie_collections (
            id UUID NOT NULL,
            tmdb_collection_id INT NOT NULL,
            name TEXT NOT NULL,
            overview TEXT DEFAULT NULL,
            poster_path TEXT DEFAULT NULL,
            backdrop_path TEXT DEFAULT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS tmdb_collection_id_unique ON movie_collections (tmdb_collection_id)');

        $this->addSql('CREATE TABLE IF NOT EXISTS party_events (
            id UUID NOT NULL,
            session_id UUID NOT NULL,
            user_id UUID NOT NULL,
            action TEXT NOT NULL,
            "position" DOUBLE PRECISION DEFAULT NULL,
            occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_party_events_session_id ON party_events (session_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_party_events_occurred_at ON party_events (occurred_at)');

        $this->addSql('CREATE TABLE IF NOT EXISTS scheduled_jobs (
            id UUID NOT NULL,
            name TEXT NOT NULL,
            expression TEXT NOT NULL,
            job_type TEXT NOT NULL,
            command TEXT NOT NULL,
            status TEXT DEFAULT \'active\' NOT NULL,
            description TEXT DEFAULT NULL,
            parameters JSONB DEFAULT \'[]\' NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            last_run_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            next_run_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            last_result TEXT DEFAULT NULL,
            run_count INT DEFAULT 0 NOT NULL,
            last_failure_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            last_error TEXT DEFAULT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_scheduled_jobs_status ON scheduled_jobs (status)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_scheduled_jobs_next_run_at ON scheduled_jobs (next_run_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS scheduled_jobs CASCADE');
        $this->addSql('DROP TABLE IF EXISTS party_events CASCADE');
        $this->addSql('DROP TABLE IF EXISTS movie_collections CASCADE');
        $this->addSql('DROP TABLE IF EXISTS library_file_index CASCADE');
        $this->addSql('DROP TABLE IF EXISTS genre_movie CASCADE');
        $this->addSql('DROP TABLE IF EXISTS login_blocks CASCADE');
    }
}
