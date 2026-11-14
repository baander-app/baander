<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115090000 extends AbstractMigration
{
    private const string CATALOG_FAMILIES = '{catalog,libraries,activity,playlists,recommendations,admin-metadata}';
    private const string ALBUM_COLUMNS = 'id,public_id,library_id,title,type,mbid,discogs_id,spotify_id,year,label,catalog_number,barcode,country,language,disambiguation,annotation,locked_fields,merged_from,cover_image_id,created_at,release_mbid,matched_at';
    private const string SONG_COLUMNS = 'id,public_id,album_id,title,path,size,mime_type,length,lyrics,track,disc,year,comment,hash,bitrate,sample_rate,channels,codec,explicit,energy,danceability,valence,acousticness,instrumentalness,liveness,spechiness,loudness,mbid,discogs_id,spotify_id,locked_fields,created_at,container_format,stream_index,matched_fields';
    private const string MATCH_STATE_COLUMNS = 'album_id,state,source,due_at,reason,checked_song_count,checked_at,best_score';

    public function getDescription(): string
    {
        return 'Store the MusicBrainz release an album matched and when, which song fields a match set, and which albums wait for a match or a review.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '2s'");
        // A release ID is stored the way MusicBrainz writes it: a lowercase UUID.
        $this->addSql(<<<'SQL'
            ALTER TABLE albums
                ADD release_mbid text,
                ADD matched_at timestamptz,
                ADD CONSTRAINT chk_albums_release_mbid_format CHECK (release_mbid ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$')
            SQL);
        $this->addSql("ALTER TABLE songs ADD matched_fields jsonb DEFAULT '[]'::jsonb NOT NULL");
        // Its own table, because a column on albums would publish an admin-only mark to every library reader.
        // A review always says why and when the matcher checked the album; the review list relies on both.
        $this->addSql(<<<'SQL'
            CREATE TABLE album_match_states (
                album_id uuid NOT NULL,
                state text NOT NULL,
                source text NOT NULL,
                due_at timestamptz NOT NULL,
                reason text,
                checked_song_count integer,
                checked_at timestamptz,
                best_score smallint,
                CONSTRAINT album_match_states_pkey PRIMARY KEY (album_id),
                CONSTRAINT chk_album_match_states_state CHECK (state IN ('requested', 'needs_review')),
                CONSTRAINT chk_album_match_states_source CHECK (source IN ('ingest', 'admin')),
                CONSTRAINT chk_album_match_states_reason CHECK (reason IN ('no_candidates', 'low_score', 'close_runner_up', 'track_count_differs', 'track_lengths_differ')),
                CONSTRAINT chk_album_match_states_review CHECK (state <> 'needs_review' OR (reason IS NOT NULL AND checked_at IS NOT NULL))
            )
            SQL);
        $this->addSql('ALTER TABLE album_match_states ADD CONSTRAINT fk_album_match_states_album_id FOREIGN KEY (album_id) REFERENCES albums (id) ON DELETE CASCADE');
        // The matcher takes the oldest due request first.
        $this->addSql('CREATE INDEX idx_album_match_states_state_due_at ON album_match_states USING btree (state, due_at)');
        foreach ([
            'albums' => [self::ALBUM_COLUMNS, self::CATALOG_FAMILIES, '{library}'],
            'songs' => [self::SONG_COLUMNS, self::CATALOG_FAMILIES, '{album}'],
            'album_match_states' => [self::MATCH_STATE_COLUMNS, '{admin-metadata}', '{admin}'],
        ] as $table => [$columns, $families, $routes]) {
            $arguments = "'{" . $columns . "}', '" . $families . "', '" . $routes . "'";
            $this->addSql("CREATE OR REPLACE TRIGGER query_capture_before BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
            $this->addSql("CREATE OR REPLACE TRIGGER query_capture_insert AFTER INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION query_live_capture_row({$arguments})");
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Matched releases, matched song fields and match states cannot be removed: dropping them loses which values came from MusicBrainz and which albums wait for a review.');
    }
}
