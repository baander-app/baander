<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128124500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record when a video placement was checked for an outstanding review.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        $this->addSql('ALTER TABLE video_placements ADD review_checked_at TIMESTAMPTZ');
        $this->addSql('UPDATE video_placements SET review_checked_at = clock_timestamp() WHERE review_reason IS NOT NULL');
        $arguments = "'{id,public_id,library_id,video_id,series_id,movie_id,relative_path,raw_parse,extra_kind,assigned,review_reason,sort_order,created_at,review_checked_at}', '{admin-metadata}', '{admin}'";
        $this->addSql("CREATE OR REPLACE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON video_placements FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
        $this->addSql("CREATE OR REPLACE TRIGGER query_capture_insert AFTER INSERT ON video_placements FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing placement check times would discard the review list ordering.');
    }
}
