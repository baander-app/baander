<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260716235039 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sync database schema with entity mappings: drop orphaned user_libraries table, fix column types (movie_collections, notifications, job_monitors), add missing columns (third_party_credentials tokens), enforce party_members.session_id FK, and align oauth_device_codes/user_sidebar_configs indexes.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_libraries DROP CONSTRAINT user_libraries_user_id_fkey');
        $this->addSql('ALTER TABLE user_libraries DROP CONSTRAINT user_libraries_library_id_fkey');
        $this->addSql('DROP TABLE user_libraries');
        $this->addSql('ALTER TABLE job_monitors ALTER status TYPE VARCHAR');
        $this->addSql('DROP INDEX idx_lyrics_song_id');
        $this->addSql('ALTER TABLE movie_collections ALTER name TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE movie_collections ALTER poster_path TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE movie_collections ALTER backdrop_path TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE notifications ALTER public_id TYPE TEXT');
        $this->addSql('DROP INDEX idx_oauth_device_codes_user_code');
        $this->addSql('CREATE UNIQUE INDEX oauth_device_codes_user_code_unique ON oauth_device_codes (user_code)');
        $this->addSql('ALTER TABLE party_members ADD CONSTRAINT FK_57514DD4613FECDF FOREIGN KEY (session_id) REFERENCES party_sessions (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE third_party_credentials ADD access_token TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE third_party_credentials ADD refresh_token TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE transcode_sessions ALTER job_id DROP NOT NULL');
        $this->addSql('DROP INDEX user_sidebar_configs_user_id_key');
        $this->addSql('CREATE INDEX IDX_35D5E9C7A76ED395 ON user_sidebar_configs (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_libraries (id UUID NOT NULL, user_id UUID NOT NULL, library_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_user_libraries_library_id ON user_libraries (library_id)');
        $this->addSql('CREATE INDEX idx_user_libraries_user_id ON user_libraries (user_id)');
        $this->addSql('CREATE UNIQUE INDEX user_libraries_user_library_unique ON user_libraries (user_id, library_id)');
        $this->addSql('ALTER TABLE user_libraries ADD CONSTRAINT user_libraries_user_id_fkey FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_libraries ADD CONSTRAINT user_libraries_library_id_fkey FOREIGN KEY (library_id) REFERENCES libraries (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE job_monitors ALTER status TYPE TEXT');
        $this->addSql('CREATE INDEX idx_lyrics_song_id ON lyrics (song_id)');
        $this->addSql('ALTER TABLE movie_collections ALTER name TYPE TEXT');
        $this->addSql('ALTER TABLE movie_collections ALTER poster_path TYPE TEXT');
        $this->addSql('ALTER TABLE movie_collections ALTER backdrop_path TYPE TEXT');
        $this->addSql('ALTER TABLE notifications ALTER public_id TYPE VARCHAR(21)');
        $this->addSql('DROP INDEX oauth_device_codes_user_code_unique');
        $this->addSql('CREATE INDEX idx_oauth_device_codes_user_code ON oauth_device_codes (user_code)');
        $this->addSql('ALTER TABLE party_members DROP CONSTRAINT FK_57514DD4613FECDF');
        $this->addSql('ALTER TABLE third_party_credentials DROP access_token');
        $this->addSql('ALTER TABLE third_party_credentials DROP refresh_token');
        $this->addSql('ALTER TABLE transcode_sessions ALTER job_id SET NOT NULL');
        $this->addSql('DROP INDEX IDX_35D5E9C7A76ED395');
        $this->addSql('CREATE UNIQUE INDEX user_sidebar_configs_user_id_key ON user_sidebar_configs (user_id)');
    }
}
