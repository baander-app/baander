<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record on each song when an album match last compared it with the album\'s release.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        // Matching a matched album's new songs selects the songs without this mark. A watermark on
        // the album missed a song committed while a match ran: it was neither compared nor newer.
        $this->addSql('ALTER TABLE songs ADD match_checked_at timestamptz');
        // The songs a match compared before the mark existed: those of a matched album that are no
        // newer than its match. The column is not one the capture trigger watches, so this publishes
        // no live update.
        $this->addSql(<<<'SQL'
            UPDATE songs SET match_checked_at = albums.matched_at
            FROM albums
            WHERE albums.id = songs.album_id AND albums.matched_at IS NOT NULL AND songs.created_at <= albums.matched_at
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The match check time cannot be removed: dropping it loses which songs a match compared, and every matched album would match its songs again.');
    }
}
