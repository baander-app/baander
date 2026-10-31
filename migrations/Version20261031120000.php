<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Include super administrators in live recommendation scopes and skip unmatched file dependencies.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '250ms'");
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION query_live_recommendation_readers(source_type text, source_id text, target_type text, target_id text)
            RETURNS TABLE(scope_id text) LANGUAGE sql STABLE AS $$
                SELECT u.id::text FROM users u
                WHERE u.roles ?| ARRAY['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']
                   OR (EXISTS(SELECT 1 FROM query_live_entity_libraries(source_type,source_id) q JOIN user_library_access a ON a.library_id=q.scope_id::uuid WHERE a.user_id=u.id)
                   AND EXISTS(SELECT 1 FROM query_live_entity_libraries(target_type,target_id) q JOIN user_library_access a ON a.library_id=q.scope_id::uuid WHERE a.user_id=u.id))
            $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION query_live_capture(source_table text, row_data jsonb, families text[], routes text[])
            RETURNS TABLE(family text, scope_kind text, scope_id text) LANGUAGE plpgsql AS $$
            DECLARE
                library_ids text[];
                playlist_ids text[];
                user_ids text[];
                access_token_ids uuid[] := ARRAY[]::uuid[];
                song_ids text[];
                album_ids text[];
                artist_ids text[];
                movie_ids text[];
                family_name text;
            BEGIN
                IF 'token'=ANY(routes) THEN
                    access_token_ids:=array_append(access_token_ids,nullif(row_data->>'token_id','')::uuid);
                END IF;
                IF 'refresh-token'=ANY(routes) THEN
                    access_token_ids:=array_append(access_token_ids,nullif(row_data->>'access_token_id','')::uuid);
                END IF;
                SELECT coalesce(array_agg(DISTINCT q.scope_id) FILTER (WHERE q.scope_id IS NOT NULL), ARRAY[]::text[])
                INTO library_ids FROM unnest(routes) r CROSS JOIN LATERAL query_live_libraries(r,row_data) q;
                SELECT coalesce(array_agg(DISTINCT q.id) FILTER (WHERE q.id IS NOT NULL), ARRAY[]::text[]) INTO playlist_ids
                FROM (
                    SELECT row_data->>'playlist_id' id WHERE 'playlist'=ANY(routes) OR 'image'=ANY(routes)
                    UNION SELECT row_data->>'id' WHERE 'playlist-self'=ANY(routes)
                ) q;
                SELECT coalesce(array_agg(DISTINCT q.id) FILTER (WHERE q.id IS NOT NULL), ARRAY[]::text[]) INTO user_ids
                FROM (
                    SELECT row_data->>'user_id' id WHERE 'user'=ANY(routes) OR 'recommendation'=ANY(routes)
                    UNION SELECT row_data->>'id' WHERE 'self'=ANY(routes)
                    UNION SELECT row_data->>'owner_user_id' WHERE 'transcode-owner'=ANY(routes)
                    UNION SELECT t.user_id::text FROM oauth_access_tokens t WHERE t.id=ANY(access_token_ids)
                    UNION SELECT p.user_id::text FROM playlists p WHERE p.id=ANY(playlist_ids::uuid[])
                    UNION SELECT c.user_id::text FROM playlist_collaborators c WHERE c.playlist_id=ANY(playlist_ids::uuid[])
                    UNION SELECT row_data->>'user_id' WHERE source_table='playlists'
                ) q;

                song_ids:=ARRAY[]::text[];
                album_ids:=ARRAY[]::text[];
                artist_ids:=ARRAY[]::text[];
                movie_ids:=ARRAY[]::text[];
                -- Resolve dependent owners while the old relationships are still present.
                IF cardinality(library_ids)>0 OR source_table IN ('songs','albums','artists','movies') THEN
                SELECT coalesce(array_agg(DISTINCT s.id::text), ARRAY[]::text[]) INTO song_ids FROM songs s
                WHERE (source_table='songs' AND s.id = nullif(row_data->>'id','')::uuid)
                   OR (source_table IN ('albums','artist_album','genre_album') AND s.album_id=coalesce(row_data->>'album_id',row_data->>'id')::uuid)
                   OR (source_table IN ('artist_song','genre_song','lyrics') AND s.id = nullif(row_data->>'song_id','')::uuid)
                   OR (source_table='artists' AND (EXISTS(SELECT 1 FROM artist_song ar WHERE ar.song_id=s.id AND ar.artist_id = nullif(row_data->>'id','')::uuid) OR EXISTS(SELECT 1 FROM artist_album ar WHERE ar.album_id=s.album_id AND ar.artist_id = nullif(row_data->>'id','')::uuid)))
                   OR (source_table='genres' AND (EXISTS(SELECT 1 FROM genre_song g WHERE g.song_id=s.id AND g.genre_id = nullif(row_data->>'id','')::uuid) OR EXISTS(SELECT 1 FROM genre_album g WHERE g.album_id=s.album_id AND g.genre_id = nullif(row_data->>'id','')::uuid)))
                   OR (source_table='images' AND (s.album_id = nullif(row_data->>'album_id','')::uuid OR EXISTS(SELECT 1 FROM albums a WHERE a.id=s.album_id AND a.cover_image_id = nullif(row_data->>'id','')::uuid) OR EXISTS(SELECT 1 FROM artist_song ar WHERE ar.song_id=s.id AND (ar.artist_id = nullif(row_data->>'artist_id','')::uuid OR EXISTS(SELECT 1 FROM artists a WHERE a.id=ar.artist_id AND a.cover_image_id = nullif(row_data->>'id','')::uuid))) OR EXISTS(SELECT 1 FROM artist_album ar WHERE ar.album_id=s.album_id AND (ar.artist_id = nullif(row_data->>'artist_id','')::uuid OR EXISTS(SELECT 1 FROM artists a WHERE a.id=ar.artist_id AND a.cover_image_id = nullif(row_data->>'id','')::uuid)))))
                   OR (source_table='library_file_index' AND s.path=row_data->>'path' AND EXISTS(SELECT 1 FROM albums a WHERE a.id=s.album_id AND a.library_id = nullif(row_data->>'library_id','')::uuid))
                   OR (source_table='libraries' AND EXISTS(SELECT 1 FROM albums a WHERE a.id=s.album_id AND a.library_id = nullif(row_data->>'id','')::uuid));
                IF source_table='songs' THEN song_ids:=array_append(song_ids,row_data->>'id'); END IF;
                SELECT coalesce(array_agg(DISTINCT q.id), ARRAY[]::text[]) INTO album_ids FROM (
                    SELECT s.album_id::text id FROM songs s WHERE s.id=ANY(song_ids::uuid[])
                    UNION SELECT row_data->>'id' WHERE source_table='albums'
                    UNION SELECT row_data->>'album_id' WHERE source_table IN ('songs','artist_album','genre_album','images') AND row_data->>'album_id' IS NOT NULL
                ) q;
                SELECT coalesce(array_agg(DISTINCT q.id), ARRAY[]::text[]) INTO artist_ids FROM (
                    SELECT ar.artist_id::text id FROM artist_song ar WHERE ar.song_id=ANY(song_ids::uuid[])
                    UNION SELECT ar.artist_id::text FROM artist_album ar WHERE ar.album_id=ANY(album_ids::uuid[])
                    UNION SELECT row_data->>'id' WHERE source_table='artists'
                    UNION SELECT row_data->>'artist_id' WHERE source_table IN ('artist_song','artist_album','images') AND row_data->>'artist_id' IS NOT NULL
                ) q;
                SELECT coalesce(array_agg(DISTINCT m.id::text), ARRAY[]::text[]) INTO movie_ids FROM movies m
                WHERE (source_table='movies' AND m.id = nullif(row_data->>'id','')::uuid)
                   OR (source_table IN ('movie_video','genre_movie') AND m.id = nullif(row_data->>'movie_id','')::uuid)
                   OR (source_table='videos' AND EXISTS(SELECT 1 FROM movie_video mv WHERE mv.movie_id=m.id AND mv.video_id = nullif(row_data->>'id','')::uuid))
                   OR (source_table='movie_collections' AND m.tmdb_collection_id=nullif(row_data->>'tmdb_collection_id','')::integer)
                   OR (source_table='genres' AND EXISTS(SELECT 1 FROM genre_movie gm WHERE gm.movie_id=m.id AND gm.genre_id = nullif(row_data->>'id','')::uuid))
                   OR (source_table='libraries' AND m.library_id = nullif(row_data->>'id','')::uuid);
                IF source_table='movies' THEN movie_ids:=array_append(movie_ids,row_data->>'id'); END IF;

                END IF;

                FOREACH family_name IN ARRAY families LOOP
                    IF family_name LIKE 'admin-%' THEN
                        RETURN QUERY SELECT family_name,'admin'::text,'*'::text;
                    ELSIF source_table='library_file_index'
                        AND cardinality(song_ids)=0 AND cardinality(album_ids)=0
                        AND cardinality(artist_ids)=0 AND cardinality(movie_ids)=0 THEN
                        RETURN QUERY SELECT DISTINCT family_name,q.kind,q.id FROM (
                            SELECT 'user' kind,unnest(user_ids) id
                            UNION SELECT 'library',unnest(library_ids) WHERE family_name IN ('catalog','libraries','lyrics')
                            UNION SELECT 'playlist',unnest(playlist_ids) WHERE family_name IN ('catalog','playlists')
                            UNION SELECT 'global','*' WHERE 'public'=ANY(routes)
                        ) q WHERE q.id IS NOT NULL;
                    ELSE
                        RETURN QUERY SELECT DISTINCT family_name,q.kind,q.id FROM (
                            SELECT 'user' kind,unnest(user_ids) id
                            UNION SELECT 'library',unnest(library_ids) WHERE family_name IN ('catalog','libraries','lyrics')
                            UNION SELECT 'playlist',unnest(playlist_ids) WHERE family_name IN ('catalog','playlists')
                            UNION SELECT 'global','*' WHERE 'public'=ANY(routes)
                            UNION SELECT 'user',a.user_id::text FROM media_activities a WHERE family_name='activity' AND (a.song_id=ANY(song_ids::uuid[]) OR a.album_id=ANY(album_ids::uuid[]) OR a.artist_id=ANY(artist_ids::uuid[]) OR a.movie_id=ANY(movie_ids::uuid[]))
                            UNION SELECT 'playlist',ps.playlist_id::text FROM playlist_song ps WHERE family_name='playlists' AND ps.song_id=ANY(song_ids::uuid[])
                            UNION SELECT 'user',p.user_id::text FROM playlists p JOIN playlist_song ps ON ps.playlist_id=p.id WHERE family_name='playlists' AND ps.song_id=ANY(song_ids::uuid[])
                            UNION SELECT 'user',c.user_id::text FROM playlist_collaborators c JOIN playlist_song ps ON ps.playlist_id=c.playlist_id WHERE family_name='playlists' AND ps.song_id=ANY(song_ids::uuid[])
                            UNION SELECT 'user',r.user_id::text FROM recommendations r WHERE family_name='recommendations' AND r.user_id IS NOT NULL AND (
                                (r.source_type='song' AND r.source_id=ANY(song_ids)) OR (r.target_type='song' AND r.target_id=ANY(song_ids))
                                OR (r.source_type='album' AND r.source_id=ANY(album_ids)) OR (r.target_type='album' AND r.target_id=ANY(album_ids))
                                OR (r.source_type='artist' AND r.source_id=ANY(artist_ids)) OR (r.target_type='artist' AND r.target_id=ANY(artist_ids))
                                OR (r.source_type='movie' AND r.source_id=ANY(movie_ids)) OR (r.target_type='movie' AND r.target_id=ANY(movie_ids)))
                            UNION SELECT 'user',a.user_id::text FROM media_activities a WHERE family_name='recommendations' AND a.song_id=ANY(song_ids::uuid[])
                            UNION SELECT 'user',reader.scope_id FROM recommendations r CROSS JOIN LATERAL query_live_recommendation_readers(r.source_type,r.source_id,r.target_type,r.target_id) reader WHERE family_name='recommendations' AND r.user_id IS NULL AND (
                                (r.source_type='song' AND r.source_id=ANY(song_ids)) OR (r.target_type='song' AND r.target_id=ANY(song_ids))
                                OR (r.source_type='album' AND r.source_id=ANY(album_ids)) OR (r.target_type='album' AND r.target_id=ANY(album_ids))
                                OR (r.source_type='artist' AND r.source_id=ANY(artist_ids)) OR (r.target_type='artist' AND r.target_id=ANY(artist_ids))
                                OR (r.source_type='movie' AND r.source_id=ANY(movie_ids)) OR (r.target_type='movie' AND r.target_id=ANY(movie_ids)))
                            UNION SELECT 'user',reader.scope_id FROM query_live_recommendation_readers(row_data->>'source_type',row_data->>'source_id',row_data->>'target_type',row_data->>'target_id') reader WHERE family_name='recommendations' AND source_table='recommendations' AND row_data->>'user_id' IS NULL
                            UNION SELECT 'user',u.id::text FROM users u WHERE family_name='recommendations' AND source_table IN ('recommendation_jobs','system_settings') AND row_data->>'user_id' IS NULL
                                AND (u.roles ?| ARRAY['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] OR EXISTS(SELECT 1 FROM user_library_access a WHERE a.user_id=u.id) OR EXISTS(SELECT 1 FROM recommendations r WHERE r.user_id=u.id))
                        ) q WHERE q.id IS NOT NULL;
                    END IF;
                END LOOP;
            END;
            $$
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Live capture behavior requires a forward correction. Captured changes and revision tombstones cannot be reconstructed.');
    }
}
