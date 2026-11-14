<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114120000 extends AbstractMigration
{
    private const string FAMILIES = '{catalog,libraries,activity,playlists,recommendations,admin-metadata}';

    public function getDescription(): string
    {
        return 'Store the name each artist credit was given, and allow one artist per MusicBrainz artist ID.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        foreach (['artist_song', 'artist_album'] as $table) {
            $this->addSql("ALTER TABLE {$table} ADD credited_name text");
            // A credit made before this column carries the artist's name as it is now.
            $this->addSql("UPDATE {$table} credit SET credited_name = artist.name FROM artists artist WHERE artist.id = credit.artist_id");
            $this->addSql("ALTER TABLE {$table} ALTER COLUMN credited_name SET NOT NULL, ADD CONSTRAINT chk_{$table}_credited_name_not_blank CHECK (btrim(credited_name) <> '')");
        }
        // Fails while two artists share an ID: which of them keeps it is for whoever merges them.
        $this->addSql('CREATE UNIQUE INDEX uniq_artists_mbid ON artists USING btree (mbid)');
        // The triggers learn the column only after the backfill, so the backfill publishes no live update.
        foreach ([
            'artist_song' => ['{id,artist_id,song_id,role,credited_name}', '{song}'],
            'artist_album' => ['{id,artist_id,album_id,role,credited_name}', '{album}'],
        ] as $table => [$columns, $routes]) {
            $arguments = "'" . $columns . "', '" . self::FAMILIES . "', '" . $routes . "'";
            $this->addSql("CREATE OR REPLACE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
            $this->addSql("CREATE OR REPLACE TRIGGER query_capture_insert AFTER INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Credited names cannot be removed: they keep the names songs and albums were credited under, which upgrading again would replace with the artists\' own names.');
    }
}
