<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the unique index backing RecommendationPoolWorker's upsert.
 *
 * RecommendationPoolWorker inserts song-to-song recommendations with
 * ON CONFLICT (source_type, source_id, target_type, target_id, name, user_id)
 * DO UPDATE ..., but no matching unique constraint ever existed — so every
 * insert in incremental mode would fail with SQLSTATE[42P10] "there is no
 * unique or exclusion constraint matching the ON CONFLICT specification".
 *
 * user_id is always NULL for song-to-song recommendations, so the index uses
 * NULLS NOT DISTINCT (PostgreSQL >= 15) to make NULLs collide and the upsert
 * actually deduplicate. A ctid-based de-duplication runs first so the index
 * creation cannot fail on rows left behind by prior direct ORM inserts.
 */
final class Version720260703AddRecommendationsUniqueIndex extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique index on recommendations (source_type, source_id, target_type, target_id, name, user_id) for the generation upsert';
    }

    public function up(Schema $schema): void
    {
        // Collapse any pre-existing duplicates (keep the physically-last row per key),
        // treating NULL user_id as equal so creation cannot fail.
        $this->addSql("DELETE FROM recommendations a USING recommendations b
            WHERE a.ctid < b.ctid
              AND a.source_type IS NOT DISTINCT FROM b.source_type
              AND a.source_id IS NOT DISTINCT FROM b.source_id
              AND a.target_type IS NOT DISTINCT FROM b.target_type
              AND a.target_id IS NOT DISTINCT FROM b.target_id
              AND a.name IS NOT DISTINCT FROM b.name
              AND a.user_id IS NOT DISTINCT FROM b.user_id");

        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS recommendations_source_target_name_user_uniq
            ON recommendations (source_type, source_id, target_type, target_id, name, user_id)
            NULLS NOT DISTINCT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS recommendations_source_target_name_user_uniq');
    }
}
