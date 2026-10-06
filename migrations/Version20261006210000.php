<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store application string columns as TEXT; the application enforces their formats and lengths.';
    }

    public function up(Schema $schema): void
    {
        // VARCHAR to TEXT is binary coercible: PostgreSQL rewrites neither the tables nor their
        // btree indexes, and the job_monitors status CHECK keeps comparing the same text values.
        // The dropped defaults were explicit NULL::character varying casts; omitted values stay NULL.
        $this->addSql('ALTER TABLE movies
            ALTER COLUMN backdrop_url TYPE TEXT, ALTER COLUMN backdrop_url DROP DEFAULT,
            ALTER COLUMN collection_name TYPE TEXT, ALTER COLUMN collection_name DROP DEFAULT,
            ALTER COLUMN imdb_id TYPE TEXT, ALTER COLUMN imdb_id DROP DEFAULT,
            ALTER COLUMN original_language TYPE TEXT, ALTER COLUMN original_language DROP DEFAULT,
            ALTER COLUMN poster_url TYPE TEXT, ALTER COLUMN poster_url DROP DEFAULT,
            ALTER COLUMN tagline TYPE TEXT, ALTER COLUMN tagline DROP DEFAULT');
        $this->addSql('ALTER TABLE movie_collections
            ALTER COLUMN backdrop_path TYPE TEXT,
            ALTER COLUMN name TYPE TEXT,
            ALTER COLUMN poster_path TYPE TEXT');
        $this->addSql('ALTER TABLE job_monitors ALTER COLUMN status TYPE TEXT');
        $this->addSql('ALTER TABLE oauth_auth_codes ALTER COLUMN code_challenge_method TYPE TEXT, ALTER COLUMN code_challenge_method DROP DEFAULT');
        $this->addSql('ALTER TABLE domain_event_outbox_delivery ALTER COLUMN notification_id TYPE TEXT');
        $this->addSql('ALTER TABLE domain_event_outbox_receipt ALTER COLUMN consumer TYPE TEXT');
    }

    public function down(Schema $schema): void
    {
        // Fails rather than truncates when a stored value exceeds its former limit.
        $this->addSql('ALTER TABLE movies
            ALTER COLUMN backdrop_url TYPE VARCHAR(255), ALTER COLUMN backdrop_url SET DEFAULT NULL,
            ALTER COLUMN collection_name TYPE VARCHAR(255), ALTER COLUMN collection_name SET DEFAULT NULL,
            ALTER COLUMN imdb_id TYPE VARCHAR(255), ALTER COLUMN imdb_id SET DEFAULT NULL,
            ALTER COLUMN original_language TYPE VARCHAR(255), ALTER COLUMN original_language SET DEFAULT NULL,
            ALTER COLUMN poster_url TYPE VARCHAR(255), ALTER COLUMN poster_url SET DEFAULT NULL,
            ALTER COLUMN tagline TYPE VARCHAR(255), ALTER COLUMN tagline SET DEFAULT NULL');
        $this->addSql('ALTER TABLE movie_collections
            ALTER COLUMN backdrop_path TYPE VARCHAR(255),
            ALTER COLUMN name TYPE VARCHAR(255),
            ALTER COLUMN poster_path TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE job_monitors ALTER COLUMN status TYPE VARCHAR');
        $this->addSql('ALTER TABLE oauth_auth_codes ALTER COLUMN code_challenge_method TYPE VARCHAR(20), ALTER COLUMN code_challenge_method SET DEFAULT NULL');
        $this->addSql('ALTER TABLE domain_event_outbox_delivery ALTER COLUMN notification_id TYPE VARCHAR(64)');
        $this->addSql('ALTER TABLE domain_event_outbox_receipt ALTER COLUMN consumer TYPE VARCHAR(128)');
    }
}
